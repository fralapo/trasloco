<?php
/**
 * Database-backed lease preventing overlapping selective table restores.
 * The lease is renewed between import requests and expires after a hard crash.
 */
class Trasloco_Selective_Restore_Lock {
    const OPTION = 'trasloco_selective_restore_lock';
    const LEASE_SECONDS = 1800;

    public static function acquire( $owner ) {
        if ( ! self::valid_owner( $owner ) ) {
            return false;
        }
        $payload = self::payload( $owner );
        if ( add_option( self::OPTION, $payload, '', false ) ) {
            return true;
        }
        $current = get_option( self::OPTION, '' );
        $decoded = self::decode( $current );
        if ( $decoded && $decoded['expires'] > time() ) {
            return $decoded['owner'] === $owner ? self::renew( $owner ) : false;
        }
        global $wpdb;
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::OPTION,
            $current
        ) );
        wp_cache_delete( self::OPTION, 'options' );
        return $deleted === 1 && add_option( self::OPTION, $payload, '', false );
    }

    public static function renew( $owner ) {
        if ( ! self::valid_owner( $owner ) ) {
            return false;
        }
        $current = get_option( self::OPTION, '' );
        $decoded = self::decode( $current );
        if ( ! $decoded || $decoded['owner'] !== $owner || $decoded['expires'] <= time() ) {
            return false;
        }
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->options,
            array( 'option_value' => self::payload( $owner ) ),
            array( 'option_name' => self::OPTION, 'option_value' => $current ),
            array( '%s' ),
            array( '%s', '%s' )
        );
        wp_cache_delete( self::OPTION, 'options' );
        return $updated === 1;
    }

    public static function release( $owner ) {
        if ( ! self::valid_owner( $owner ) ) {
            return false;
        }
        $current = get_option( self::OPTION, '' );
        $decoded = self::decode( $current );
        if ( ! $decoded || $decoded['owner'] !== $owner ) {
            return false;
        }
        global $wpdb;
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::OPTION,
            $current
        ) );
        wp_cache_delete( self::OPTION, 'options' );
        return $deleted === 1;
    }

    public static function active() {
        $current = get_option( self::OPTION, '' );
        $decoded = self::decode( $current );
        if ( $decoded && $decoded['expires'] > time() ) {
            return true;
        }
        if ( $current !== '' ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::OPTION,
                $current
            ) );
            wp_cache_delete( self::OPTION, 'options' );
        }
        return false;
    }

    private static function valid_owner( $owner ) {
        return is_string( $owner ) && preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $owner );
    }

    private static function payload( $owner ) {
        return wp_json_encode( array(
            'owner' => $owner,
            'expires' => time() + self::LEASE_SECONDS,
            'nonce' => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true ),
        ) );
    }

    private static function decode( $payload ) {
        $decoded = is_string( $payload ) ? json_decode( $payload, true ) : null;
        if ( ! is_array( $decoded ) || ! isset( $decoded['owner'], $decoded['expires'] ) ||
            ! self::valid_owner( $decoded['owner'] ) || ! is_numeric( $decoded['expires'] ) ) {
            return null;
        }
        return array( 'owner' => $decoded['owner'], 'expires' => (int) $decoded['expires'] );
    }
}
