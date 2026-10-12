<?php
/**
 * Create an independently recoverable snapshot BEFORE replacing files or SQL.
 * Not an automatic rollback: an operator must still restore the safety copy.
 */
class Trasloco_Import_Recovery {
    public static function execute( $params ) {
        if ( empty( $params['archive'] ) || empty( $params['storage'] ) ) {
            throw new Trasloco_Import_Exception( 'Cannot create a recovery point without an import archive.' );
        }
        Trasloco_Status::info( __( 'Creating a recovery point for this site before replacing anything...', TRASLOCO_PLUGIN_NAME ) );
        $key = hash( 'sha256', 'recovery|' . $params['storage'] . '|' . $params['archive'] );
        $id = Trasloco_Job_Store::create( 'backup', array( 'recovery_point' => true ), $key );
        if ( is_wp_error( $id ) || ! $id ) {
            throw new Trasloco_Import_Exception( 'The safety backup could not be created. Import was stopped.' );
        }
        $previous = Trasloco_Job_Store::get( $id );
        if ( $previous && $previous['state'] === 'completed' &&
            ! empty( $previous['checkpoint']['archive'] ) ) {
            $name = $previous['checkpoint']['archive'];
            require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
            if ( Trasloco_Integrity::verify( trasloco_backup_path( array( 'archive' => $name ) ) )['status'] === 'verified' ) {
                $params['recovery_archive'] = $name;
                return $params;
            }
        }
        $result = Trasloco_Job_Worker::run( $id );
        if ( $result['status'] !== 'completed' || empty( $result['archive'] ) ) {
            throw new Trasloco_Import_Exception( 'The safety backup failed. The destination has not been replaced.' );
        }
        $params['recovery_archive'] = $result['archive'];
        Trasloco_Status::info( __( 'Recovery point verified. Starting the import...', TRASLOCO_PLUGIN_NAME ) );
        return $params;
    }
}
