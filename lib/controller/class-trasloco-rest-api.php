<?php
/**
 * Strictly read-only REST visibility into local backup inventory.
 * WordPress cookie+REST nonces or revocable Application Passwords authenticate
 * callers; every route independently requires manage_options.
 */
class Trasloco_REST_API {
    public static function register() {
        register_rest_route( 'trasloco/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_health' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_backups' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)/verify', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'queue_verification' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'archive' => array( 'required' => true, 'type' => 'string' ) ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/(?P<id>[0-9]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_job' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/(?P<id>[0-9]+)/cancel', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'cancel_job' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_backup' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'archive' => array( 'required' => true, 'type' => 'string' ) ),
        ) );
    }


    public static function queue_verification( $request ) {
        $archive = $request->get_param( 'archive' );
        try {
            $path = trasloco_backup_path( array( 'archive' => $archive ) );
            if ( ! is_file( $path ) || ! is_readable( $path ) ) {
                return new WP_Error( 'trasloco_not_found', 'Backup not found.', array( 'status' => 404 ) );
            }
        } catch ( Exception $e ) {
            return new WP_Error( 'trasloco_invalid_archive', 'Invalid archive filename.', array( 'status' => 400 ) );
        }
        // Every user action starts a new verification; a retrying caller may
        // provide one stable idempotency key in the REST request body.
        $key = $request->get_param( 'idempotency_key' );
        if ( $key === null || $key === '' ) {
            $key = wp_generate_password( 32, false, false );
        }
        $id = Trasloco_Job_Store::create( 'verify', array( 'archive' => $archive ), $key );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( ! Trasloco_Job_Worker::schedule( $id ) ) {
            return new WP_Error( 'trasloco_schedule_failed', 'Job saved but no worker could be scheduled. Use WP-CLI to run it.', array( 'status' => 503, 'job_id' => $id ) );
        }
        return new WP_REST_Response( array( 'job_id' => $id, 'state' => Trasloco_Job_Store::get( $id )['state'] ), 202 );
    }

    public static function get_job( $request ) {
        $job = Trasloco_Job_Store::get( (int) $request->get_param( 'id' ) );
        return $job === null
            ? new WP_Error( 'trasloco_job_not_found', 'Job not found.', array( 'status' => 404 ) )
            : rest_ensure_response( $job );
    }

    public static function cancel_job( $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! Trasloco_Job_Store::cancel( $id ) ) {
            return new WP_Error( 'trasloco_job_not_cancellable', 'Job not found or already completed.', array( 'status' => 409 ) );
        }
        return rest_ensure_response( Trasloco_Job_Store::get( $id ) );
    }


    /**
     * Remote monitoring endpoint for future multi-site/agency consoles.
     * Does not return credentials, filesystem paths or user account details.
     * A sidecar only means "manifest available", never "verified".
     */
    public static function get_health( $request ) {
        global $wpdb;
        $backups = (new Trasloco_Backups())->get_files();
        $latest = ! empty( $backups ) ? $backups[0] : null;
        $schedule = Trasloco_Scheduled_Backups::settings();
        $table = Trasloco_Job_Store::table();
        $failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state = 'failed'" );
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state IN ('pending','running')" );
        return rest_ensure_response( array(
            'site' => home_url(),
            'backups' => count( $backups ),
            'latest_backup' => $latest ? self::metadata( $latest['filename'] ) : null,
            'schedule' => $schedule['frequency'],
            'next_scheduled_at' => $schedule['frequency'] === 'off' ? null : wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ),
            'failed_jobs' => $failed,
            'active_jobs' => $pending,
        ) );
    }

    public static function can_manage() {
        return current_user_can( 'manage_options' );
    }

    public static function metadata( $filename ) {
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        $path = trasloco_backup_path( array( 'archive' => $filename ) );
        if ( ! is_file( $path ) ) {
            return null;
        }
        $has_manifest = is_file( Trasloco_Integrity::manifest_path( $path ) );
        // Do not hash huge files during listing: a checksum requires an
        // explicit verify job/CLI operation and must never be implied here.
        return array(
            'filename' => basename( $path ),
            'size_bytes' => (int) filesize( $path ),
            'updated_at' => (int) filemtime( $path ),
            'integrity' => $has_manifest ? 'manifest_available' : 'unverified',
        );
    }

    public static function list_backups( $request ) {
        $model = new Trasloco_Backups();
        $items = array();
        foreach ( $model->get_files() as $backup ) {
            if ( count( $items ) >= 100 ) {
                break;
            }
            try {
                $metadata = self::metadata( $backup['filename'] );
                if ( $metadata !== null ) {
                    $items[] = $metadata;
                }
            } catch ( Exception $e ) {
                // Skip malformed/legacy paths; never return server paths.
            }
        }
        return rest_ensure_response( array( 'backups' => $items, 'limit' => 100 ) );
    }

    public static function get_backup( $request ) {
        $archive = $request->get_param( 'archive' );
        try {
            $metadata = self::metadata( $archive );
        } catch ( Exception $e ) {
            $metadata = null;
        }
        if ( $metadata === null ) {
            return new WP_Error( 'trasloco_backup_not_found', 'Backup not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $metadata );
    }
}
