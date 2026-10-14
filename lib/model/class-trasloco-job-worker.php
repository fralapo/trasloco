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
        Trasloco_Job_Store::fail_exhausted_transfers();
        foreach ( Trasloco_Job_Store::recoverable_verifications( 10 ) as $id ) {
            self::schedule( $id );
        }
        foreach ( Trasloco_Job_Store::recoverable_transfers( 10 ) as $id ) {
            self::schedule( $id );
        }
    }

    /** Persist a credential-free request for a verified offsite download. */
    public static function enqueue_offsite_download( $archive ) {
        if ( ! is_string( $archive ) || strlen( $archive ) > 206 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $archive ) ||
            strpos( $archive, '..' ) !== false ) {
            return new WP_Error( 'trasloco_invalid_archive', 'Invalid backup archive name.' );
        }
        $id = Trasloco_Job_Store::create( 'transfer',
            array( 'operation' => 'download', 'archive' => $archive ),
            wp_generate_password( 32, false, false ) );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        // A failed enqueue remains pending and will be picked up by reconcile().
        self::schedule( $id );
        return $id;
    }

    /** Persist an upload request only when its provider supports replayable parts. */
    public static function enqueue_offsite_upload( $archive, $allow_unencrypted = false, $delete_after_upload = false ) {
        if ( ! is_string( $archive ) || strlen( $archive ) > 206 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $archive ) ||
            strpos( $archive, '..' ) !== false || ! is_bool( $allow_unencrypted ) ||
            ! is_bool( $delete_after_upload ) || ( $delete_after_upload && substr( $archive, -4 ) !== '.enc' ) ) {
            return new WP_Error( 'trasloco_invalid_archive', 'Invalid backup archive name.' );
        }
        if ( substr( $archive, -4 ) !== '.enc' && ! $allow_unencrypted ) {
            return new WP_Error( 'trasloco_plaintext_consent', 'Plaintext backups require explicit consent.' );
        }
        try {
            $adapter = Trasloco_Storage_Factory::configured();
            if ( ! $adapter instanceof Trasloco_Resumable_Storage_Adapter ) {
                return new WP_Error( 'trasloco_storage_not_resumable', 'The configured provider does not support resumable uploads.' );
            }
        } catch ( Throwable $error ) {
            return new WP_Error( 'trasloco_storage_unavailable', 'The configured storage provider is unavailable.' );
        }
        $payload = array(
            'operation' => 'upload', 'archive' => $archive,
            'allow_unencrypted' => $allow_unencrypted,
        );
        if ( $delete_after_upload ) {
            $payload['delete_after_upload'] = true;
        }
        $id = Trasloco_Job_Store::create( 'transfer', $payload, wp_generate_password( 32, false, false ) );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        self::schedule( $id );
        return $id;
    }

    /** Run a leased offsite download and persist byte-level progress. */
    private static function transfer_download( $id, $owner, $payload ) {
        if ( ! isset( $payload['operation'], $payload['archive'] ) ||
            $payload['operation'] !== 'download' || ! is_string( $payload['archive'] ) ||
            strlen( $payload['archive'] ) > 206 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $payload['archive'] ) ||
            strpos( $payload['archive'], '..' ) !== false || count( $payload ) !== 2 ) {
            throw new RuntimeException( 'Invalid offsite download request.' );
        }
        if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
            throw new RuntimeException( 'Unable to prepare the local backup directory.' );
        }
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        $adapter = Trasloco_Storage_Factory::configured();
        $transfer = new Trasloco_Offsite_Transfer( $adapter, TRASLOCO_BACKUPS_PATH );
        $archive = $payload['archive'];
        $last_renewal = time();
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 1,
            array( 'phase' => 'downloading', 'archive' => $archive, 'bytes' => 0, 'total_bytes' => 0 ) ) ) {
            throw new RuntimeException( 'Offsite download was cancelled or its lease was lost.' );
        }
        $result = $transfer->download( $archive, function ( $bytes, $total ) use ( $id, $owner, $archive, &$last_renewal ) {
            if ( time() - $last_renewal >= 120 ) {
                if ( ! Trasloco_Job_Store::renew( $id, $owner, 300 ) ) {
                    return false;
                }
                $last_renewal = time();
            }
            $progress = $total > 0 ? min( 95, 5 + (int) floor( ( $bytes / $total ) * 85 ) ) : 90;
            return Trasloco_Job_Store::checkpoint( $id, $owner, $progress, array(
                'phase' => 'downloading', 'archive' => $archive,
                'bytes' => (int) $bytes, 'total_bytes' => (int) $total,
            ) );
        } );
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 99, array(
            'phase' => 'verified', 'archive' => $archive, 'bytes' => (int) $result['bytes'],
        ) ) ) {
            throw new RuntimeException( 'Offsite download was cancelled or its lease was lost.' );
        }
        return $archive;
    }

    /** Run a chunked upload using only the provider's resumable storage capability. */
    private static function transfer_upload( $id, $owner, $payload ) {
        if ( ! isset( $payload['operation'], $payload['archive'], $payload['allow_unencrypted'] ) ||
            $payload['operation'] !== 'upload' || ! is_string( $payload['archive'] ) ||
            ! is_bool( $payload['allow_unencrypted'] ) ||
            strlen( $payload['archive'] ) > 206 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $payload['archive'] ) ||
            strpos( $payload['archive'], '..' ) !== false ||
            ( count( $payload ) !== 3 && !( count( $payload ) === 4 &&
                isset( $payload['delete_after_upload'] ) && $payload['delete_after_upload'] === true &&
                $payload['allow_unencrypted'] === false &&
                substr( $payload['archive'], -4 ) === '.enc' ) ) ) {
            throw new RuntimeException( 'Invalid offsite upload request.' );
        }
        if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
            throw new RuntimeException( 'Unable to prepare the local backup directory.' );
        }
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        $adapter = Trasloco_Storage_Factory::configured();
        if ( ! $adapter instanceof Trasloco_Resumable_Storage_Adapter ) {
            throw new RuntimeException( 'Configured storage does not support resumable uploads.' );
        }
        $transfer = new Trasloco_Offsite_Transfer( $adapter, TRASLOCO_BACKUPS_PATH );
        $archive = $payload['archive'];
        $last_renewal = time();
        $last_checkpoint = $last_renewal;
        $last_checkpoint_bytes = 0;
        $previous = Trasloco_Job_Store::get_internal( $id );
        $resume_state = is_array( $previous ) && isset( $previous['checkpoint']['provider_state'] ) &&
            is_array( $previous['checkpoint']['provider_state'] ) ? $previous['checkpoint']['provider_state'] : null;
        $last_checkpoint_state = $resume_state;
        $initial_checkpoint = array( 'phase' => 'uploading', 'archive' => $archive, 'bytes' => 0, 'total_bytes' => 0 );
        if ( $resume_state !== null ) {
            $initial_checkpoint['provider_state'] = $resume_state;
        }
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 1,
            $initial_checkpoint ) ) {
            throw new RuntimeException( 'Offsite upload was cancelled or its lease was lost.' );
        }
        $result = $transfer->upload( $archive, $payload['allow_unencrypted'], function ( $bytes, $total, $provider_state = null ) use ( $id, $owner, $archive, &$last_renewal, &$last_checkpoint, &$last_checkpoint_bytes, &$last_checkpoint_state ) {
            $now = time();
            $bytes = (int) $bytes;
            if ( $bytes === $last_checkpoint_bytes && $provider_state === $last_checkpoint_state &&
                $now - $last_checkpoint < 60 && $now - $last_renewal < 120 ) {
                return true;
            }
            if ( $now - $last_renewal >= 120 ) {
                if ( ! Trasloco_Job_Store::renew( $id, $owner, 300 ) ) {
                    return false;
                }
                $last_renewal = $now;
            }
            $progress = $total > 0 ? min( 95, 5 + (int) floor( ( $bytes / $total ) * 85 ) ) : 90;
            $checkpoint = array(
                'phase' => 'uploading', 'archive' => $archive,
                'bytes' => $bytes, 'total_bytes' => (int) $total,
            );
            if ( is_array( $provider_state ) ) {
                $checkpoint['provider_state'] = $provider_state;
            }
            $saved = Trasloco_Job_Store::checkpoint( $id, $owner, $progress, $checkpoint );
            if ( $saved ) {
                $last_checkpoint = $now;
                $last_checkpoint_bytes = $bytes;
                $last_checkpoint_state = $provider_state;
            }
            return $saved;
        }, $resume_state );
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 99, array(
            'phase' => 'verified', 'archive' => $archive, 'bytes' => (int) $result['bytes'],
        ) ) ) {
            throw new RuntimeException( 'Offsite upload was cancelled or its lease was lost.' );
        }
        return $archive;
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
    private static function acquire_backup_lock( $id ) {
        $owner = hash( 'sha256', 'trasloco-job-backup:' . (int) $id );
        $handle = Trasloco_Export_Lock::acquire( $owner, HOUR_IN_SECONDS );
        if ( $handle === false ) {
            throw new RuntimeException( 'Another site export is already running.' );
        }
        return array( $handle, $owner );
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
        if ( ! empty( $payload['upload_offsite'] ) ) {
            $passphrase = defined( 'TRASLOCO_ARCHIVE_PASSPHRASE' )
                ? TRASLOCO_ARCHIVE_PASSPHRASE : getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
            if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
                throw new RuntimeException( 'Automatic offsite backup requires TRASLOCO_ARCHIVE_PASSPHRASE (at least 12 characters).' );
            }
            $encrypted_name = $params['archive'] . '.enc';
            $encrypted_path = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $encrypted_name;
            $encrypted_manifest = Trasloco_Integrity::manifest_path( $encrypted_path );
            $encrypted_created = false;
            try {
                if ( file_exists( $encrypted_manifest ) || is_link( $encrypted_manifest ) ) {
                    throw new RuntimeException( 'Encrypted backup manifest already exists.' );
                }
                require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
                Trasloco_Encryption::encrypt( $archive, $encrypted_path, $passphrase );
                $encrypted_created = true;
                Trasloco_Integrity::create_chunked( $encrypted_path );
                $upload_id = self::enqueue_offsite_upload( $encrypted_name, false, true );
                if ( is_wp_error( $upload_id ) || ! $upload_id ) {
                    throw new RuntimeException( 'Unable to queue encrypted offsite backup.' );
                }
            } catch ( Throwable $error ) {
                if ( $encrypted_created ) {
                    @unlink( $encrypted_path );
                    @unlink( $encrypted_manifest );
                }
                throw $error;
            }
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
        if ( ! $record || ! in_array( $record['kind'], array( 'verify', 'backup', 'transfer' ), true ) ||
            ! is_array( $record['payload'] ) ) {
            return array( 'status' => 'invalid' );
        }

        // Only workers with an acquired lease are allowed to update state.
        $owner = 'worker-' . wp_generate_password( 24, false, false );
        $lease_seconds = $record['kind'] === 'transfer' ? 300 : 3600;
        if ( ! Trasloco_Job_Store::claim( $id, $owner, $lease_seconds ) ) {
            return array( 'status' => 'not_claimed' );
        }
        try {
            if ( $record['kind'] === 'backup' ) {
                list( $backup_lock, $backup_lock_owner ) = self::acquire_backup_lock( $id );
                try {
                    // The export pipeline emits progress events while streaming.
                    // A bounded heartbeat keeps its job lease alive beyond
                    // the initial hour, without restarting the export.
                    $GLOBALS['trasloco_active_backup_job'] = array(
                        'id' => $id, 'owner' => $owner, 'last_renew' => time(),
                    );
                    $GLOBALS['trasloco_active_export_lock'] = array(
                        'handle' => $backup_lock, 'owner' => $backup_lock_owner,
                        'lease_seconds' => HOUR_IN_SECONDS, 'last_renew' => time(),
                    );
                    $name = self::backup( $id, $owner, $record['payload'] );
                } finally {
                    unset( $GLOBALS['trasloco_active_backup_job'] );
                    unset( $GLOBALS['trasloco_active_export_lock'] );
                    Trasloco_Export_Lock::release( $backup_lock, $backup_lock_owner );
                }
                if ( ! Trasloco_Job_Store::finish( $id, $owner ) ) {
                    return array( 'status' => 'not_claimed' );
                }
                return array( 'status' => 'completed', 'archive' => $name );
            }
            if ( $record['kind'] === 'transfer' ) {
                $name = isset( $record['payload']['operation'] ) && $record['payload']['operation'] === 'upload'
                    ? self::transfer_upload( $id, $owner, $record['payload'] )
                    : self::transfer_download( $id, $owner, $record['payload'] );
                if ( ! Trasloco_Job_Store::finish( $id, $owner ) ) {
                    return array( 'status' => 'not_claimed' );
                }
                if ( $record['payload']['operation'] === 'upload' && ! empty( $record['payload']['delete_after_upload'] ) ) {
                    $uploaded = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $record['payload']['archive'];
                    if ( is_file( $uploaded ) && ! is_link( $uploaded ) ) {
                        @unlink( $uploaded );
                        @unlink( Trasloco_Integrity::manifest_path( $uploaded ) );
                    }
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
            // Provider exceptions can include URLs or request details. Keep
            // the durable record secret-free and useful without those values.
            $failure = $record['kind'] === 'transfer' ? 'Offsite transfer failed; inspect provider availability and job history.' : $e->getMessage();
            $failed = Trasloco_Job_Store::fail( $id, $owner, $failure );
            if ( $record['kind'] === 'backup' && empty( $record['payload']['recovery_point'] ) ) {
                do_action( 'trasloco_scheduled_backup_failed', $id );
            } elseif ( $record['kind'] === 'transfer' && $failed ) {
                do_action( 'trasloco_offsite_transfer_failed', $id );
            }
            return array( 'status' => 'failed' );
        }
    }
}
