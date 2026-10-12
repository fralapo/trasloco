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
    public static function run( $id ) {
        $id = (int) $id;
        $record = Trasloco_Job_Store::workload( $id );
        if ( ! $record || $record['kind'] !== 'verify' ||
            ! is_array( $record['payload'] ) || ! isset( $record['payload']['archive'] ) ) {
            return array( 'status' => 'invalid' );
        }

        // Only workers with an acquired lease are allowed to update state.
        $owner = 'worker-' . wp_generate_password( 24, false, false );
        if ( ! Trasloco_Job_Store::claim( $id, $owner, 3600 ) ) {
            return array( 'status' => 'not_claimed' );
        }
        try {
            $filename = $record['payload']['archive'];
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
