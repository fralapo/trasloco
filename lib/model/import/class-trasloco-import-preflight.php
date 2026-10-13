<?php
/**
 * Complete read-only archive metadata walk before any destination file or
 * database changes. Validating just the first few extracted metadata files
 * is insufficient: unsafe entries can appear later in a .wpress archive.
 */
class Trasloco_Import_Preflight {
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
        return $params;
    }
}
