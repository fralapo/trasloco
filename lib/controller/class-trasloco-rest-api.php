<?php
/**
 * Strictly read-only REST visibility into local backup inventory.
 * WordPress cookie+REST nonces or revocable Application Passwords authenticate
 * callers; every route independently requires manage_options.
 */
class Trasloco_REST_API {
    /** Register the optional WordPress Abilities API category (Core 6.9+). */
    public static function register_ability_category() {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }
        wp_register_ability_category( 'trasloco', array(
            'label' => __( 'Trasloco', TRASLOCO_PLUGIN_NAME ),
            'description' => __( 'Read-only backup and recovery readiness information.', TRASLOCO_PLUGIN_NAME ),
        ) );
    }

    /** Expose the existing bounded preflight as an admin-only read-only ability. */
    public static function register_site_readiness_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        wp_register_ability( 'trasloco/site-readiness', array(
            'category' => 'trasloco',
            'label' => __( 'Inspect Trasloco site readiness', TRASLOCO_PLUGIN_NAME ),
            'description' => __( 'Returns advisory backup storage, server capability and cron diagnostics.', TRASLOCO_PLUGIN_NAME ),
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'ready_for_local_backup' => array( 'type' => 'boolean' ),
                    'backup_directory' => array( 'type' => 'string' ),
                    'backup_web_access' => array( 'type' => 'string' ),
                    'free_space_bytes' => array( 'anyOf' => array(
                        array( 'type' => 'integer' ), array( 'type' => 'null' ),
                    ) ),
                    'php_64_bit' => array( 'type' => 'boolean' ),
                    'mysqli_available' => array( 'type' => 'boolean' ),
                    'sodium_available' => array( 'type' => 'boolean' ),
                    'wp_cron' => array( 'type' => 'string' ),
                    'issues' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'warning' => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback' => array( __CLASS__, 'site_readiness' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'meta' => array(
                'show_in_rest' => true,
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    /** Return the same advisory report as /trasloco/v1/preflight. */
    public static function site_readiness() {
        return Trasloco_Preflight::report();
    }

    public static function register() {
        register_rest_route( 'trasloco/v1', '/recovery-points', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_recovery_points' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/preflight', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'preflight' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
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
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)/preview', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'preview_backup' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)/verify', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'queue_verification' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'archive' => array( 'required' => true, 'type' => 'string' ) ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_jobs' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/(?P<id>[0-9]+)/retry', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'retry_job' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
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


    public static function list_jobs( $request ) {
        return rest_ensure_response( array( 'jobs' => Trasloco_Job_Store::recent( 20 ) ) );
    }

    public static function retry_job( $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! Trasloco_Job_Store::retry_verification( $id ) ) {
            return new WP_Error( 'trasloco_retry_not_allowed', 'Only failed or cancelled verification jobs may be retried (up to three attempts).', array( 'status' => 409 ) );
        }
        if ( ! Trasloco_Job_Worker::schedule( $id ) ) {
            return new WP_Error( 'trasloco_schedule_failed', 'Retry recorded but worker scheduling failed.', array( 'status' => 503 ) );
        }
        return new WP_REST_Response( Trasloco_Job_Store::get( $id ), 202 );
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


    public static function preview_backup( $request ) {
        try {
            $path = trasloco_backup_path( array( 'archive' => $request->get_param( 'archive' ) ) );
            return rest_ensure_response( Trasloco_Archive_Inspector::inspect( $path, 30 ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'trasloco_invalid_preview', 'The archive could not be inspected.', array( 'status' => 400 ) );
        }
    }

    public static function preflight( $request ) {
        return rest_ensure_response( Trasloco_Preflight::report() );
    }


    /** Read-only recovery journal inventory for site administrators only. */
    public static function list_recovery_points( $request ) {
        try {
            $records = Trasloco_Recovery_Journal::entries( TRASLOCO_BACKUPS_PATH, 100 );
        } catch ( Throwable $e ) {
            return new WP_Error( 'trasloco_recovery_unavailable',
                'Recovery receipts could not be read.', array( 'status' => 503 ) );
        }
        $pending = array_values( array_filter( $records, function ( $entry ) {
            return $entry['status'] === 'pending';
        } ) );
        return rest_ensure_response( array(
            'pending' => $pending,
            'limit' => 100,
            'warning' => 'A pending receipt is not proof of recoverability or an automatic rollback.',
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
        // Preserve the original first 100 results for existing clients, but
        // allow bounded paging for large multi-client agency installations.
        $raw_page = $request->get_param( 'page' );
        $raw_limit = $request->get_param( 'per_page' );
        $page = $raw_page === null ? 1 : filter_var(
            $raw_page, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 10000 ) )
        );
        $per_page = $raw_limit === null ? 100 : filter_var(
            $raw_limit, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 100 ) )
        );
        if ( $page === false || $per_page === false ) {
            return new WP_Error( 'trasloco_invalid_pagination',
                'page must be 1–10000 and per_page must be 1–100.',
                array( 'status' => 400 ) );
        }
        $model = new Trasloco_Backups();
        $backups = $model->get_files();
        $total = count( $backups );
        $items = array();
        foreach ( array_slice( $backups, ( $page - 1 ) * $per_page, $per_page ) as $backup ) {
            try {
                $metadata = self::metadata( $backup['filename'] );
                if ( $metadata !== null ) {
                    $items[] = $metadata;
                }
            } catch ( Exception $e ) {
                // Malformed archive metadata never discloses server paths.
            }
        }
        $response = rest_ensure_response( array(
            'backups' => $items,
            'limit' => $per_page,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
        ) );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
        return $response;
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
