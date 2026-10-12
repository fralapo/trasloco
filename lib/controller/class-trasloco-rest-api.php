<?php
/**
 * Strictly read-only REST visibility into local backup inventory.
 * WordPress cookie+REST nonces or revocable Application Passwords authenticate
 * callers; every route independently requires manage_options.
 */
class Trasloco_REST_API {
    public static function register() {
        register_rest_route( 'trasloco/v1', '/backups', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_backups' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_backup' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'archive' => array( 'required' => true, 'type' => 'string' ) ),
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
