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
        add_action( 'admin_post_trasloco_verify_archive', array( __CLASS__, 'submit_verification' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_intervals' ) );
        add_action( 'init', array( __CLASS__, 'ensure_reconciler' ), 40 );
        add_action( 'trasloco_reconcile_jobs', array( __CLASS__, 'reconcile' ) );
    }



    /**
     * Progressive-enhancement action for the archive table: no JavaScript
     * required, and never computes a huge checksum in the admin page request.
     */
    public static function submit_verification() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'trasloco_verify_archive', 'trasloco_verify_nonce' );
        $status = 'error';
        $archive = isset( $_POST['archive'] ) && is_string( $_POST['archive'] )
            ? wp_unslash( $_POST['archive'] ) : '';
        if ( preg_match( '/^[a-zA-Z0-9_.-]+\\.wpress$/D', $archive ) && strlen( $archive ) <= 220 ) {
            try {
                $path = trasloco_backup_path( array( 'archive' => $archive ) );
                if ( is_file( $path ) && is_readable( $path ) && ! is_link( $path ) ) {
                    $id = Trasloco_Job_Store::create( 'verify',
                        array( 'archive' => $archive ), wp_generate_password( 32, false, false ) );
                    if ( ! is_wp_error( $id ) && $id && self::schedule( $id ) ) {
                        $status = 'queued';
                    }
                }
            } catch ( Exception $ignored ) {
                // Avoid leaking filesystem paths to browser errors.
            }
        }
        wp_safe_redirect( add_query_arg( 'trasloco_verify_status', $status,
            admin_url( 'admin.php?page=trasloco_backups' ) ) );
        exit;
    }

    public static function cron_intervals( $schedules ) {
        $schedules['trasloco_five_minutes'] = array(
            'interval' => 300,
            'display' => 'Every five minutes (Trasloco)',
        );
        return $schedules;
    }

    public static function ensure_reconciler() {
        if ( ! wp_next_scheduled( 'trasloco_reconcile_jobs' ) ) {
            wp_schedule_event( time() + 300, 'trasloco_five_minutes', 'trasloco_reconcile_jobs' );
        }
    }

    /** Safe to run repeatedly; claim() keeps worker ownership atomic. */
    public static function reconcile() {
        Trasloco_Job_Store::fail_abandoned_backups();
        Trasloco_Job_Store::fail_exhausted_verifications();
        foreach ( Trasloco_Job_Store::recoverable_verifications( 10 ) as $id ) {
            self::schedule( $id );
        }
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

    /**
     * Exclusive lock for server-side exports and recovery snapshots. File
     * descriptor ownership is process-scoped and released on PHP process exit.
     * Do not silently wait and generate two backups from inconsistent data.
     */
    private static function acquire_backup_lock() {
        if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
            throw new RuntimeException( 'Unable to create backup directory for exclusive lock.' );
        }
        $path = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.trasloco-export.lock';
        if ( is_link( $path ) ) {
            throw new RuntimeException( 'Backup lock may not be a symbolic link.' );
        }
        $handle = @fopen( $path, 'c+' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Backup lock file cannot be opened.' );
        }
        if ( ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            throw new RuntimeException( 'Another server-side backup is already running.' );
        }
        return $handle;
    }

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
        $params = $is_recovery
            ? array( 'archive' => 'recovery-' . trasloco_archive_file() )
            : array();
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
        $prior = array_values( array_filter( $model->get_retention_files(), function ( $entry ) use ( $params ) {
            return $entry['filename'] !== $params['archive'];
        } ) );
        // Always protect the newly verified archive, even when creation
        // timestamps tie and sort order is not deterministic.
        foreach ( array_slice( $prior, $keep - 1 ) as $entry ) {
            $model->delete_file( $entry['filename'] );
        }
        return $params['archive'];
    }


    /** Advance bounded v2 verification with an owned, crash-replay checkpoint. */
    private static function verify_chunk_job( $id, $owner, $archive, $manifest ) {
        $size = (int) filesize( $archive );
        $mtime = (int) filemtime( $archive );
        if ( $size !== $manifest['size_bytes'] || is_link( $archive ) ) {
            throw new RuntimeException( 'Archive changed size or is a symlink.' );
        }
        $previous = Trasloco_Job_Store::get( $id );
        $checkpoint = ! empty( $previous['checkpoint']['phase'] ) &&
            $previous['checkpoint']['phase'] === 'chunks' ? $previous['checkpoint'] : array();
        $index = 0;
        if ( ! empty( $checkpoint ) ) {
            if ( ! isset( $checkpoint['manifest_sha256'], $checkpoint['size_bytes'],
                         $checkpoint['file_mtime'], $checkpoint['next_chunk'] ) ||
                $checkpoint['manifest_sha256'] !== $manifest['_manifest_sha256'] ||
                (int) $checkpoint['size_bytes'] !== $size ||
                (int) $checkpoint['file_mtime'] !== $mtime ||
                ! is_int( $checkpoint['next_chunk'] ) ||
                $checkpoint['next_chunk'] < 0 ||
                $checkpoint['next_chunk'] > count( $manifest['chunks'] ) ) {
                throw new RuntimeException( 'Archive or manifest changed during staged verification.' );
            }
            $index = $checkpoint['next_chunk'];
        }
        $total = count( $manifest['chunks'] );
        $stop = min( $total, $index + 2 );
        while ( $index < $stop ) {
            if ( ! Trasloco_Integrity::verify_chunk( $archive, $manifest, $index ) ) {
                throw new RuntimeException( 'Archive chunk checksum mismatch.' );
            }
            $index++;
        }
        clearstatcache( true, $archive );
        if ( filesize( $archive ) !== $size || filemtime( $archive ) !== $mtime ||
             hash_file( 'sha256', Trasloco_Integrity::manifest_path( $archive ) ) !== $manifest['_manifest_sha256'] ) {
            throw new RuntimeException( 'Archive or manifest modified during staged verification.' );
        }
        if ( $index === $total ) {
            // A chunk manifest can be edited independently of its whole-file
            // digest. Recheck the original global SHA-256 before marking this
            // operation complete. It still does not authenticate origin.
            $whole = @hash_file( 'sha256', $archive );
            if ( ! is_string( $whole ) || ! hash_equals( $manifest['sha256'], $whole ) ) {
                throw new RuntimeException( 'Archive whole-file checksum mismatch.' );
            }
            clearstatcache( true, $archive );
            if ( filesize( $archive ) !== $size || filemtime( $archive ) !== $mtime ||
                 hash_file( 'sha256', Trasloco_Integrity::manifest_path( $archive ) ) !== $manifest['_manifest_sha256'] ) {
                throw new RuntimeException( 'Archive or manifest changed during final verification.' );
            }
            if ( ! Trasloco_Job_Store::finish( $id, $owner ) ) {
                return array( 'status' => 'not_claimed' );
            }
            return array( 'status' => 'completed' );
        }
        $progress = min( 99, (int) floor( ( $index / $total ) * 100 ) );
        $next = array( 'phase' => 'chunks', 'manifest_sha256' => $manifest['_manifest_sha256'],
            'file_mtime' => $mtime, 'size_bytes' => $size, 'next_chunk' => $index );
        if ( ! Trasloco_Job_Store::yield_verification( $id, $owner, $progress, $next ) ) {
            return array( 'status' => 'not_claimed' );
        }
        // Failed scheduling is picked up by the reconciler without repeating
        // previously verified chunks.
        self::schedule( $id );
        return array( 'status' => 'pending', 'progress' => $progress );
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
                $backup_lock = self::acquire_backup_lock();
                try {
                    // The export pipeline emits progress events while streaming.
                    // A bounded heartbeat keeps its job lease alive beyond
                    // the initial hour, without restarting the export.
                    $GLOBALS['trasloco_active_backup_job'] = array(
                        'id' => $id, 'owner' => $owner, 'last_renew' => time(),
                    );
                    $name = self::backup( $id, $owner, $record['payload'] );
                } finally {
                    unset( $GLOBALS['trasloco_active_backup_job'] );
                    flock( $backup_lock, LOCK_UN );
                    fclose( $backup_lock );
                }
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
            require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
            $v2 = Trasloco_Integrity::chunked_manifest( $archive );
            if ( $v2 !== null ) {
                $result = self::verify_chunk_job( $id, $owner, $archive, $v2 );
                $result['archive'] = $filename;
                return $result;
            }
            if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 5, array( 'phase' => 'verifying' ) ) ) {
                throw new RuntimeException( 'Verification cancelled or lease lost.' );
            }
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
            if ( $record['kind'] === 'backup' && empty( $record['payload']['recovery_point'] ) ) {
                do_action( 'trasloco_scheduled_backup_failed', $id );
            }
            return array( 'status' => 'failed' );
        }
    }
}
