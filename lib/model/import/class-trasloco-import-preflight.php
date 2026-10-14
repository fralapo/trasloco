<?php
/**
 * Complete read-only archive metadata walk before any destination file or
 * database changes. Validating just the first few extracted metadata files
 * is insufficient: unsafe entries can appear later in a .wpress archive.
 */
class Trasloco_Import_Preflight {
    /**
     * Validate optional file exclusions without weakening archive validation.
     * Paths are relative to wp-content and are matched against archive names.
     */
    public static function normalize_excluded_paths( $paths ) {
        if ( ! is_array( $paths ) || count( $paths ) > 64 ) {
            throw new Trasloco_Import_Exception( 'Import stopped: excluded file paths are invalid.' );
        }
        $allowed_roots = array( 'uploads', 'plugins', 'themes', 'mu-plugins' );
        $normalized = array();
        foreach ( $paths as $path ) {
            if ( ! is_string( $path ) || $path === '' || strlen( $path ) > 512 ||
                 strpos( $path, '/' ) === 0 || substr( $path, -1 ) === '/' ||
                 strpos( $path, "\\" ) !== false || preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
                throw new Trasloco_Import_Exception( 'Import stopped: excluded file paths are invalid.' );
            }
            $parts = explode( '/', $path );
            if ( $path === '' || in_array( $parts[0], array( '.', '..' ), true ) ||
                 ! in_array( $parts[0], $allowed_roots, true ) ) {
                throw new Trasloco_Import_Exception( 'Import stopped: excluded file paths must be relative to uploads, plugins, themes, or mu-plugins.' );
            }
            foreach ( $parts as $part ) {
                if ( $part === '' || $part === '.' || $part === '..' ) {
                    throw new Trasloco_Import_Exception( 'Import stopped: excluded file paths are invalid.' );
                }
            }
            $normalized[] = $path;
        }
        return array_values( array_unique( $normalized ) );
    }
    /**
     * Precondition for destructive file/database replacement. An archive may
     * have a valid header and footer but still omit essential SQL metadata.
     * Legacy files-only exports explicitly opt out with NoDatabase=true.
     */
    public static function assert_required_payload( $summary, $config ) {
        if ( ! is_array( $summary ) || ! isset( $summary['restore_inputs'] ) ||
             ! is_array( $summary['restore_inputs'] ) || ! is_array( $config ) || empty( $config ) ||
             empty( $summary['restore_inputs']['package.json'] ) ) {
            throw new Trasloco_Import_Exception(
                'Import stopped: the archive is missing valid package metadata.'
            );
        }
        if ( empty( $config['NoDatabase'] ) &&
             empty( $summary['restore_inputs']['database.sql'] ) ) {
            throw new Trasloco_Import_Exception(
                'Import stopped: this backup requires database.sql, but it is missing or empty. Destination files were not replaced.'
            );
        }
    }
    public static function execute( $params ) {
        if ( empty( $params['archive'] ) || empty( $params['storage'] ) ) {
            throw new Trasloco_Import_Exception( 'Import archive is not available for preflight.' );
        }
        $path = trasloco_archive_path( $params );
        if ( isset( $params['options']['exclude_paths'] ) ) {
            $params['options']['exclude_paths'] = self::normalize_excluded_paths( $params['options']['exclude_paths'] );
        }
        try {
            $summary = Trasloco_Archive_Inspector::inspect( $path, 0 );
            if ( ! is_array( $summary ) || $summary['files_in_archive'] < 1 ) {
                throw new RuntimeException( 'Empty archive inventory.' );
            }
        } catch ( Exception $e ) {
            // File paths and raw malicious entry names are not returned to the UI.
            throw new Trasloco_Import_Exception(
                'Import stopped: the backup contains malformed or unsafe file entries.'
            );
        }
        $package_path = trasloco_package_path( $params );
        $encoded = is_file( $package_path ) && is_readable( $package_path )
            ? @file_get_contents( $package_path ) : false;
        $config = is_string( $encoded ) ? json_decode( $encoded, true ) : null;
        self::assert_required_payload( $summary, $config );
        if ( ! empty( $params['trasloco_selective_tables'] ) ) {
            $requested = isset( $params['trasloco_selected_tables'] ) ? $params['trasloco_selected_tables'] : null;
            if ( ! is_array( $requested ) ) {
                throw new Trasloco_Import_Exception( 'Selective restore stopped: the selected table list is invalid.' );
            }
            $suffixes = array();
            foreach ( $requested as $table ) {
                if ( is_string( $table ) && strpos( $table, 'SERVMASK_PREFIX_' ) === 0 ) {
                    $table = substr( $table, strlen( 'SERVMASK_PREFIX_' ) );
                }
                $suffixes[] = $table;
            }
            require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-selective-database-restore.php';
            try {
                $plan = Trasloco_Selective_Database_Restore::plan( $params['archive'], $suffixes );
            } catch ( Throwable $error ) {
                throw new Trasloco_Import_Exception( 'Selective restore stopped: ' . $error->getMessage() );
            }
            $params['trasloco_selected_tables'] = $plan['source_tables'];
            $params['trasloco_selective_tables'] = $plan['source_tables'];
        }
        return $params;
    }
}
