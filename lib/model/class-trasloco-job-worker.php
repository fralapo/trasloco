<?php
/**
 * Stage worker for verification jobs.
 *
 * Deliberately never executes a restore in a background HTTP request:
 * that would replace the session/database and needs a dedicated recovery
 * architecture. Jobs are persisted independently from browser state.
 */
class Trasloco_Job_Worker {
    const HOOK = 'trasloco_run_job';

    public static function register() {
        add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );
    }

    public static function schedule( $id ) {
        $id = (int) $id;
        if ( $id <= 0 ) {
            return false;
        }
        if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) ) {
            return (bool) as_enqueue_async_action( self::HOOK, array( $id ), 'trasloco', true );
        }
        if ( wp_next_scheduled( self::HOOK, array( $id ) ) ) {
            return true;
        }
        return (bool) wp_schedule_single_event( time() + 1, self::HOOK, array( $id ) );
    }

    /**
     * Return an array for programmatic/CLI inspection; WP-Cron ignores it.
     */

    /** Reuse tested synchronous WP-CLI export pipeline without AJAX exits. */
    private static function backup( $id, $owner, $payload ) {
        $is_recovery = ! empty( $payload['recovery_point'] );
        $keep = isset( $payload['retention'] ) ? (int) $payload['retention'] : 0;
        if ( ! $is_recovery && ( $keep < 1 || $keep > 100 ) ) {
            throw new RuntimeException( 'Invalid retention policy.' );
        }
        if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) || ! wp_mkdir_p( TRASLOCO_STORAGE_PATH ) ) {
            throw new RuntimeException( 'Unable to create backup storage.' );
        }
        if ( ! is_file( TRASLOCO_BACKUPS_HTACCESS ) ) {
            Trasloco_File_Htaccess::create( TRASLOCO_BACKUPS_HTACCESS );
        }
        if ( ! is_file( TRASLOCO_BACKUPS_WEBCONFIG ) ) {
            Trasloco_File_Webconfig::create( TRASLOCO_BACKUPS_WEBCONFIG );
        }
        if ( ! is_file( TRASLOCO_STORAGE_PATH . '/.htaccess' ) ) {
            @file_put_contents( TRASLOCO_STORAGE_PATH . '/.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n", LOCK_EX );
        }
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 10, array( 'phase' => 'export' ) ) ) {
            throw new RuntimeException( 'Backup cancelled.' );
        }
        trasloco_setup_environment();
        $params = array();
        // Suppress nested export progress while the import displays its
        // recovery-point stage; do not discard the import's own status.
        if ( $is_recovery ) {
            $GLOBALS['trasloco_recovery_export_active'] = true;
        }
        remove_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );
        add_filter( 'trasloco_completed_timeout', '__return_zero' );
        try {
            $params = apply_filters( 'trasloco_export', $params );
        } finally {
            unset( $GLOBALS['trasloco_recovery_export_active'] );
            remove_filter( 'trasloco_completed_timeout', '__return_zero' );
            add_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );
            if ( ! empty( $params['storage'] ) ) {
                try {
                    Trasloco_Directory::delete( trasloco_storage_path( $params ) );
                } catch ( Exception $ignored ) {}
            }
        }
        if ( empty( $params['archive'] ) ) {
            throw new RuntimeException( 'Export did not return an archive.' );
        }
        $archive = trasloco_backup_path( $params );
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        if ( Trasloco_Integrity::verify( $archive )['status'] !== 'verified' ) {
            throw new RuntimeException( 'Backup did not pass integrity verification.' );
        }
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 95, array( 'phase' => $is_recovery ? 'recovery' : 'retention', 'archive' => $params['archive'] ) ) ) {
            throw new RuntimeException( 'Backup cancelled before retention.' );
        }
        if ( $is_recovery ) {
            return $params['archive'];
        }
        $model = new Trasloco_Backups();
        $prior = array_values( array_filter( $model->get_files(), function ( $entry ) use ( $params ) {
            return $entry['filename'] !== $params['archive'];
        } ) );
        // Always protect the newly verified archive, even when creation
        // timestamps tie and sort order is not deterministic.
        foreach ( array_slice( $prior, $keep - 1 ) as $entry ) {
            $model->delete_file( $entry['filename'] );
        }
        return $params['archive'];
    }

    public static function run( $id ) {
        $id = (int) $id;
        $record = Trasloco_Job_Store::workload( $id );
        if ( ! $record || ! in_array( $record['kind'], array( 'verify', 'backup' ), true ) ||
            ! is_array( $record['payload'] ) ) {
            return array( 'status' => 'invalid' );
        }

        // Only workers with an acquired lease are allowed to update state.
        $owner = 'worker-' . wp_generate_password( 24, false, false );
        if ( ! Trasloco_Job_Store::claim( $id, $owner, 3600 ) ) {
            return array( 'status' => 'not_claimed' );
        }
        try {
            if ( $record['kind'] === 'backup' ) {
                $name = self::backup( $id, $owner, $record['payload'] );
                if ( ! Trasloco_Job_Store::finish( $id, $owner ) ) {
                    return array( 'status' => 'not_claimed' );
                }
                return array( 'status' => 'completed', 'archive' => $name );
            }
            $filename = isset( $record['payload']['archive'] ) ? $record['payload']['archive'] : null;
            if ( ! is_string( $filename ) ) {
                throw new RuntimeException( 'Invalid archive reference.' );
            }
            $archive = trasloco_backup_path( array( 'archive' => $filename ) );
            if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 5, array( 'phase' => 'verifying' ) ) ) {
                throw new RuntimeException( 'Verification cancelled or lease lost.' );
            }
            require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
            $result = Trasloco_Integrity::verify( $archive );
            if ( $result['status'] !== 'verified' ) {
                throw new RuntimeException( 'Backup verification: ' . $result['status'] );
            }
            if ( ! Trasloco_Job_Store::finish( $id, $owner ) ) {
                return array( 'status' => 'not_claimed' );
            }
            return array( 'status' => 'completed', 'archive' => $filename );
        } catch ( Throwable $e ) {
            // Generic error only; never log any secret-bearing payload or path.
            Trasloco_Job_Store::fail( $id, $owner, $e->getMessage() );
            return array( 'status' => 'failed' );
        }
    }
}
