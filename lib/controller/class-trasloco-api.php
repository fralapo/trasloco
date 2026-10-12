<?php
/**
 * Read-only, capability checked backup REST endpoints.
 * WordPress Application Passwords can authenticate remote clients.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_API {
    public static function register() {
        register_rest_route( 'trasloco/v1', '/backups', array(
            'methods' => 'GET',
            'permission_callback' => array( __CLASS__, 'permit' ),
            'callback' => array( __CLASS__, 'backups' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9._-]+\\.wpress)/verify', array(
            'methods' => 'POST',
            'permission_callback' => array( __CLASS__, 'permit' ),
            'callback' => array( __CLASS__, 'verify' ),
        ) );
    }

    public static function permit() {
        return current_user_can( 'manage_options' );
    }

    public static function backups() {
        $model = new Trasloco_Backups();
        $data = array();
        foreach ( $model->get_files() as $backup ) {
            $file = $backup['filename'];
            try {
                $integrity = Trasloco_Manifest::inspect( $file, false );
                $data[] = array(
                    'archive' => $file,
                    'bytes' => $backup['size'],
                    'modified_at' => $backup['mtime'],
                    'integrity' => $integrity['status'],
                );
            } catch ( Exception $e ) {
                // Unsupported legacy filenames are not disclosed by the API.
            }
        }
        return rest_ensure_response( $data );
    }

    public static function verify( $request ) {
        try {
            return rest_ensure_response( Trasloco_Manifest::inspect( (string) $request['archive'], true ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'trasloco_invalid_archive', 'Invalid archive.', array( 'status' => 400 ) );
        }
    }
}
