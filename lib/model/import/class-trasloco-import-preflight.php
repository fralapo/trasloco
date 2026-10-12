<?php
/**
 * Complete read-only archive metadata walk before any destination file or
 * database changes. Validating just the first few extracted metadata files
 * is insufficient: unsafe entries can appear later in a .wpress archive.
 */
class Trasloco_Import_Preflight {
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
        return $params;
    }
}
