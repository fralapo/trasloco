<?php
/**
 * Read-only, bounded preflight diagnostics.
 *
 * Reports risks without running HTTP probes, disclosing filesystem paths,
 * reading secrets or altering backups. Results are advisory, never proof that
 * a destination can be restored after a catastrophe.
 */
class Trasloco_Preflight {
    public static function report() {
        $path = TRASLOCO_BACKUPS_PATH;
        $real = is_dir( $path ) ? realpath( $path ) : false;
        $document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
            ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
        if ( $document_root === false ) {
            $document_root = realpath( ABSPATH );
        }
        $under_public_root = false;
        if ( $real !== false && $document_root !== false ) {
            $root = rtrim( str_replace( '\\', '/', $document_root ), '/' ) . '/';
            $candidate = rtrim( str_replace( '\\', '/', $real ), '/' ) . '/';
            $under_public_root = strpos( $candidate, $root ) === 0;
        }

        $directory = is_link( $path ) ? 'symlink'
            : ( ! is_dir( $path ) ? 'missing'
            : ( is_writable( $path ) && is_readable( $path ) ? 'ready' : 'not_writable' ) );
        $disk = $real !== false ? @disk_free_space( $real ) : false;
        $web_risk = $under_public_root ? 'review_web_server_rules' :
            ( $real !== false ? 'outside_document_root' : 'unknown' );
        $wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

        $issues = array();
        if ( $directory !== 'ready' ) {
            $issues[] = 'backup_directory_' . $directory;
        }
        if ( $web_risk !== 'outside_document_root' ) {
            $issues[] = 'confirm_http_backup_access_is_denied';
        }
        if ( $wp_cron_disabled && wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ) !== false ) {
            $issues[] = 'configure_system_cron';
        }
        if ( PHP_INT_SIZE < 8 ) {
            $issues[] = '32_bit_php_limits_large_archives';
        }
        // Disk estimates for real restores require unpacked size and database
        // growth, so don't show arbitrary thresholds as a guarantee.
        return array(
            'ready_for_local_backup' => $directory === 'ready' && PHP_INT_SIZE >= 8,
            'backup_directory' => $directory,
            'backup_web_access' => $web_risk,
            'free_space_bytes' => $disk === false ? null : (int) $disk,
            'php_64_bit' => PHP_INT_SIZE >= 8,
            'mysqli_available' => extension_loaded( 'mysqli' ),
            'sodium_available' => function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ),
            'wp_cron' => $wp_cron_disabled ? 'external_runner_required' : 'traffic_dependent',
            'issues' => $issues,
            'warning' => 'Preflight is advisory: verify anonymous HTTP denial and rehearse disaster recovery on a separate host before production use.',
        );
    }
}
