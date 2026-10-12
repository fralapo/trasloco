<?php
/**
 * Opt-in read-only multi-site status dashboard. Site definitions are provided
 * only through wp-config.php or secure environment configuration. No secrets
 * are persisted to the wp_options table or emitted into the HTML.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Agency {
    const CACHE_SECONDS = 300;

    public static function sites() {
        if ( ! defined( 'TRASLOCO_AGENCY_SITES' ) || ! is_array( TRASLOCO_AGENCY_SITES ) ) {
            return array();
        }
        return array_slice( TRASLOCO_AGENCY_SITES, 0, 15 );
    }

    public static function inspect( $site ) {
        if ( ! is_array( $site ) || empty( $site['url'] ) || empty( $site['user'] ) || empty( $site['password'] ) ) {
            return array( 'status' => 'invalid', 'message' => 'Site configuration is incomplete.' );
        }
        $base = trailingslashit( $site['url'] );
        if ( parse_url( $base, PHP_URL_SCHEME ) !== 'https' || ! wp_http_validate_url( $base ) ) {
            return array( 'status' => 'invalid', 'message' => 'Site must have a valid public HTTPS URL.' );
        }
        // Configured host is the only allowed destination. Redirects disabled.
        $url = $base . 'wp-json/trasloco/v1/health';
        $key = 'trasloco_agency_' . hash( 'sha256', $url . '|' . (string) $site['user'] );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $result = wp_safe_remote_get( $url, array(
            'timeout' => 5,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( $site['user'] . ':' . $site['password'] ),
            ),
        ) );
        if ( is_wp_error( $result ) ) {
            $health = array( 'status' => 'unreachable', 'message' => 'Connection failed or was refused.' );
        } elseif ( wp_remote_retrieve_response_code( $result ) !== 200 ) {
            $health = array( 'status' => 'error', 'message' => 'The remote site did not authorize the health request.' );
        } else {
            $body = wp_remote_retrieve_body( $result );
            $json = strlen( $body ) <= 16384 ? json_decode( $body, true ) : null;
            if ( ! is_array( $json ) || ! isset( $json['plugin_version'], $json['active_job'] ) ) {
                $health = array( 'status' => 'invalid', 'message' => 'The remote response was malformed.' );
            } else {
                $health = array(
                    'status' => 'connected',
                    'version' => is_scalar( $json['plugin_version'] ) ? substr( (string) $json['plugin_version'], 0, 40 ) : '',
                    'job' => is_scalar( $json['active_job'] ) ? substr( (string) $json['active_job'], 0, 40 ) : '',
                    'storage' => ! empty( $json['backup_storage_writable'] ),
                    'php' => isset( $json['php_version'] ) && is_scalar( $json['php_version'] ) ? substr( (string) $json['php_version'], 0, 25 ) : '',
                );
            }
        }
        set_transient( $key, $health, self::CACHE_SECONDS );
        return $health;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        $sites = array();
        foreach ( self::sites() as $site ) {
            $sites[] = array(
                'name' => isset( $site['name'] ) && is_string( $site['name'] ) ? $site['name'] : 'WordPress site',
                'state' => self::inspect( $site ),
            );
        }
        Trasloco_Template::render( 'main/agency', array( 'sites' => $sites ) );
    }
}
