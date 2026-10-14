<?php
/**
 * Shared validation for selected-table recovery from a verified local archive.
 * This class only builds a plan; the importer applies its exact table allowlist.
 */
class Trasloco_Selective_Database_Restore {
    /** Build a destination-aware plan from the requested WordPress table suffixes. */
    public static function plan( $archive, $suffixes ) {
        global $wpdb;
        if ( is_multisite() ) {
            throw new RuntimeException( 'Selective table restore is available only on single-site WordPress.' );
        }
        if ( ! is_array( $suffixes ) || empty( $suffixes ) || count( $suffixes ) > 50 ) {
            throw new InvalidArgumentException( 'Select between 1 and 50 database tables.' );
        }
        $suffixes = array_values( array_unique( $suffixes ) );
        foreach ( $suffixes as $suffix ) {
            if ( ! is_string( $suffix ) || ! preg_match( '/^[A-Za-z0-9_$]{1,180}$/D', $suffix ) ) {
                throw new InvalidArgumentException( 'A selected table name is invalid.' );
            }
            if ( strpos( $suffix, 'basesite_' ) === 0 || preg_match( '/^[0-9]+_/', $suffix ) ) {
                throw new InvalidArgumentException( 'The selected table is not safely mappable to this single-site destination.' );
            }
        }
        if ( ! is_string( $archive ) || ! preg_match( '/^[A-Za-z0-9_.-]+\.wpress$/D', $archive ) ) {
            throw new InvalidArgumentException( 'Specify an archive filename from the local backup directory.' );
        }
        $archive_path = trasloco_backup_path( array( 'archive' => $archive ) );
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-offline-sql-preview.php';
        if ( Trasloco_Integrity::verify( $archive_path )['status'] !== 'verified' ) {
            throw new RuntimeException( 'Selective restore requires a valid SHA-256 manifest for the backup.' );
        }
        $inventory = Trasloco_Offline_SQL_Preview::preview( $archive_path );
        if ( $inventory['database_sql'] !== 'present' ) {
            throw new RuntimeException( 'The selected backup has no database SQL to restore.' );
        }
        $destination_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! is_array( $destination_tables ) ) {
            throw new RuntimeException( 'Could not read destination table names.' );
        }

        $operations = array();
        $source_tables = array();
        foreach ( $suffixes as $suffix ) {
            $source_table = 'SERVMASK_PREFIX_' . $suffix;
            if ( ! isset( $inventory['tables'][ $source_table ] ) ) {
                throw new RuntimeException( 'The selected table is not present in the verified backup: ' . $suffix );
            }
            $actions = $inventory['tables'][ $source_table ];
            if ( $actions['drop'] < 1 || $actions['create'] < 1 ) {
                throw new RuntimeException( 'The backup does not contain a complete DROP/CREATE operation for: ' . $suffix );
            }
            $destination_table = $wpdb->prefix . $suffix;
            if ( strlen( $destination_table ) > 64 ) {
                throw new RuntimeException( 'The destination table name exceeds the MySQL identifier limit.' );
            }
            $exists = in_array( $destination_table, $destination_tables, true );
            $operations[] = array(
                'source_table' => $source_table,
                'destination_table' => $destination_table,
                'destination_exists' => $exists,
                'expected_effect' => $exists ? 'replace_existing_table' : 'create_table',
                'actions' => $actions,
            );
            $source_tables[] = $source_table;
        }
        return array( 'operations' => $operations, 'source_tables' => $source_tables );
    }
}
