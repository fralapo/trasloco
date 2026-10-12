<?php
/**
 * Read-only, capability checked backup REST endpoints.
 * WordPress Application Passwords can authenticate remote clients.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_API {
    public static function register() {
        register_rest_route( 'trasloco/v1', '/health', array(
            'methods' => 'GET',
            'permission_callback' => array( __CLASS__, 'permit' ),
            'callback' => array( __CLASS__, 'health' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs', array(
            array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'permit' ), 'callback' => array( __CLASS__, 'jobs' ) ),
            array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'permit' ), 'callback' => array( __CLASS__, 'start_backup' ) ),
        ) );
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


    /** Basic Application Password / cookie auth, then WordPress capability. */
    public static function readiness() {
        global $wp_version;
        $hpos = 'woocommerce-not-active';
        if ( class_exists( 'WooCommerce' ) ) {
            $hpos = 'unknown';
            if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) &&
                 method_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
                $hpos = call_user_func( array( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) ? 'enabled' : 'disabled';
            }
        }
        $job = get_option( Trasloco_Background::ACTIVE, false );
        return array(
            'plugin_version' => TRASLOCO_VERSION,
            'wp_version' => (string) $wp_version,
            'php_version' => PHP_VERSION,
            'multisite' => is_multisite(),
            'backup_storage_writable' => is_dir( TRASLOCO_BACKUPS_PATH ) && is_writable( TRASLOCO_BACKUPS_PATH ),
            'woocommerce_hpos' => $hpos,
            'active_job' => is_array( $job ) ? (string) $job['state'] : 'idle',
        );
    }

    public static function health() {
        return rest_ensure_response( self::readiness() );
    }

    public static function jobs() {
        $active = get_option( Trasloco_Background::ACTIVE, false );
        $last = get_option( Trasloco_Background::LAST, false );
        return rest_ensure_response( array(
            'active' => is_array( $active ) ? array(
                'id' => $active['id'],
                'state' => $active['state'],
                'step' => $active['index'],
                'updated_at' => $active['updated_at'],
            ) : null,
            'last' => is_array( $last ) ? $last : null,
        ) );
    }

    public static function start_backup() {
        $id = Trasloco_Background::start();
        if ( $id === false ) {
            return new WP_Error( 'trasloco_busy', 'A backup is already active or unsupported on this site.', array( 'status' => 409 ) );
        }
        return new WP_REST_Response( array( 'id' => $id, 'state' => 'queued' ), 202 );
    }

    /** WordPress 6.9+ discovery: read-only ability with capability checks. */
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        wp_register_ability( 'trasloco/site-readiness', array(
            'label' => 'Trasloco site readiness',
            'description' => 'Inspect WordPress, PHP, backup storage and WooCommerce HPOS availability.',
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugin_version' => array( 'type' => 'string' ),
                    'wp_version' => array( 'type' => 'string' ),
                    'php_version' => array( 'type' => 'string' ),
                    'multisite' => array( 'type' => 'boolean' ),
                    'backup_storage_writable' => array( 'type' => 'boolean' ),
                    'woocommerce_hpos' => array( 'type' => 'string' ),
                    'active_job' => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback' => array( __CLASS__, 'readiness' ),
            'permission_callback' => array( __CLASS__, 'permit' ),
            'meta' => array( 'category' => 'site-info', 'show_in_rest' => false ),
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
