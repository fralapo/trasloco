<?php
/**
 * Read-only, bounded preflight preview of a .wpress backup.
 * No destination tables/files are modified by this operation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Preview {
    public static function read( $name ) {
        if ( is_multisite() ) {
            throw new RuntimeException( 'Preview currently supports single-site WordPress.' );
        }
        $archive = Trasloco_Manifest::archive_path( $name );
        $verification = Trasloco_Manifest::inspect( $name );
        if ( in_array( $verification['status'], array( 'corrupt', 'invalid', 'missing' ), true ) ) {
            throw new RuntimeException( 'Invalid or damaged backup; preview refused.' );
        }
        $reader = new Trasloco_Extractor( $archive );
        try {
            if ( ! $reader->is_valid() ) {
                throw new RuntimeException( 'Unsupported or incomplete archive structure.' );
            }
            $file_count = $reader->get_total_files_count();
            $files_bytes = $reader->get_total_files_size();
        } finally {
            $reader->close();
        }
        $tempdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-preview-' . bin2hex( random_bytes( 10 ) );
        if ( ! @mkdir( $tempdir, 0700 ) ) {
            throw new RuntimeException( 'Could not allocate a private preview directory.' );
        }
        $package_path = $tempdir . DIRECTORY_SEPARATOR . TRASLOCO_PACKAGE_NAME;
        try {
            $reader = new Trasloco_Extractor( $archive );
            try {
                $file_written = 0;
                $file_offset = 0;
                $reader->extract_by_files_array( $tempdir, array( TRASLOCO_PACKAGE_NAME ), array(), $file_written, $file_offset );
            } finally {
                $reader->close();
            }
            if ( ! is_file( $package_path ) || is_link( $package_path ) || filesize( $package_path ) > 4 * 1024 * 1024 ) {
                throw new RuntimeException( 'Archive package metadata is missing or too large.' );
            }
            $package = json_decode( (string) file_get_contents( $package_path ), true );
            if ( ! is_array( $package ) ) {
                throw new RuntimeException( 'Invalid archive package metadata.' );
            }
            $old = isset( $package['Plugins'] ) && is_array( $package['Plugins'] ) ? $package['Plugins'] : array();
            $old = array_values( array_filter( $old, 'is_string' ) );
            $new = array_values( array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' ) );
            $new_theme = (string) get_option( 'stylesheet', '' );
            $source_theme = isset( $package['Stylesheet'] ) && is_string( $package['Stylesheet'] ) ? $package['Stylesheet'] : '';
            $source_php = isset( $package['PHP']['Version'] ) && is_string( $package['PHP']['Version'] ) ? $package['PHP']['Version'] : '';
            $free = @disk_free_space( WP_CONTENT_DIR );
            clearstatcache( true, $archive );
            $bytes = filesize( $archive );
            $warnings = array();
            if ( $verification['status'] !== 'verified' ) {
                $warnings[] = 'Legacy archive: no SHA-256 manifest is available.';
            }
            if ( $source_php !== '' && version_compare( $source_php, PHP_VERSION, '>' ) ) {
                $warnings[] = 'The backup comes from a newer PHP version.';
            }
            if ( $free !== false && $free < $bytes ) {
                $warnings[] = 'Destination disk space may be insufficient.';
            }
            if ( class_exists( 'WooCommerce' ) ) {
                $warnings[] = 'WooCommerce detected: review orders and HPOS before replacing live data.';
            }
            return array(
                'archive' => $name,
                'integrity' => $verification['status'],
                'archive_bytes' => $bytes,
                'files' => $file_count,
                'content_bytes' => $files_bytes,
                'source_url' => isset( $package['SiteURL'] ) ? (string) $package['SiteURL'] : '',
                'destination_url' => site_url(),
                'source_php' => $source_php,
                'destination_php' => PHP_VERSION,
                'source_theme' => $source_theme,
                'destination_theme' => $new_theme,
                'theme_changes' => $source_theme !== $new_theme,
                'plugins_to_activate' => array_values( array_diff( $old, $new ) ),
                'plugins_to_deactivate' => array_values( array_diff( $new, $old ) ),
                'warnings' => $warnings,
            );
        } finally {
            if ( is_file( $package_path ) ) {
                @unlink( $package_path );
            }
            @rmdir( $tempdir );
        }
    }
}
