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
        Trasloco_Job_Store::fail_exhausted_crypto();
        foreach ( Trasloco_Job_Store::recoverable_verifications( 10 ) as $id ) {
            self::schedule( $id );
        }
        foreach ( Trasloco_Job_Store::recoverable_backups( 10 ) as $id ) {
            self::schedule( $id );
        }
        foreach ( Trasloco_Job_Store::recoverable_transfers( 10 ) as $id ) {
            self::schedule( $id );
        }
        foreach ( Trasloco_Job_Store::recoverable_crypto( 10 ) as $id ) {
            self::schedule( $id );
        }
    }

    /** Queue web encryption/decryption using credential-free, resumable jobs. */
    public static function enqueue_crypto( $operation, $archive ) {
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        if ( ! in_array( $operation, array( 'encrypt', 'decrypt' ), true ) || ! is_string( $archive ) ) {
            return new WP_Error( 'trasloco_invalid_crypto_job', 'Invalid encryption request.' );
        }
        $passphrase = defined( 'TRASLOCO_ARCHIVE_PASSPHRASE' ) ? TRASLOCO_ARCHIVE_PASSPHRASE : getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
        if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
            return new WP_Error( 'trasloco_crypto_unconfigured', 'Archive encryption is not configured.' );
        }
        if ( $operation === 'encrypt' ) {
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $archive ) || strpos( $archive, '..' ) !== false ) {
                return new WP_Error( 'trasloco_invalid_crypto_job', 'Invalid backup filename.' );
            }
            $source = trasloco_backup_path( array( 'archive' => $archive ) );
            if ( ! self::crypto_source_has_verification_proof( $archive, $source ) ) {
                return new WP_Error( 'trasloco_crypto_archive_unverified', 'Verify this backup again before encrypting it.' );
            }
            $target = trasloco_encrypted_backup_path( $archive . '.enc' );
        } else {
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress\.enc$/D', $archive ) || strpos( $archive, '..' ) !== false ) {
                return new WP_Error( 'trasloco_invalid_crypto_job', 'Invalid encrypted backup filename.' );
            }
            $source = trasloco_encrypted_backup_path( $archive );
            $target_name = substr( $archive, 0, -strlen( '.wpress.enc' ) ) . '-decrypted.wpress';
            $target = trasloco_backup_path( array( 'archive' => $target_name ) );
        }
        if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source ) ) {
            return new WP_Error( 'trasloco_crypto_archive_missing', 'Backup file is unavailable.' );
        }
        if ( file_exists( $target ) || is_link( $target ) || file_exists( Trasloco_Integrity::manifest_path( $target ) ) ) {
            return new WP_Error( 'trasloco_crypto_target_exists', 'The output archive already exists.' );
        }
        $id = Trasloco_Job_Store::create( 'crypto', array( 'operation' => $operation, 'archive' => $archive ),
            wp_generate_password( 32, false, false ) );
        if ( is_wp_error( $id ) ) { return $id; }
        Trasloco_Audit_Log::record( 'crypto.queued', $id );
        if ( ! self::schedule( $id ) ) {
            return new WP_Error( 'trasloco_crypto_schedule_failed', 'Encryption job was saved but could not be scheduled.', array( 'status' => 503, 'job_id' => $id ) );
        }
        return $id;
    }

    private static function crypto_source_has_verification_proof( $archive, $source ) {
        $verified = Trasloco_Job_Store::verification_statuses( array( $archive ) );
        $proof = isset( $verified[ $archive ]['proof'] ) && $verified[ $archive ]['state'] === 'completed'
            ? $verified[ $archive ]['proof'] : array();
        $manifest = Trasloco_Integrity::manifest_path( $source );
        clearstatcache( true, $source ); $source_stat = @lstat( $source );
        clearstatcache( true, $manifest ); $manifest_stat = @lstat( $manifest );
        foreach ( array( 'size_bytes','file_mtime','file_ctime','file_inode','file_device',
            'manifest_size_bytes','manifest_mtime','manifest_ctime','manifest_inode','manifest_device' ) as $field ) {
            if ( ! isset( $proof[ $field ] ) ) { return false; }
        }
        return is_array( $source_stat ) && is_array( $manifest_stat ) && ! is_link( $source ) && is_file( $source ) &&
            ! is_link( $manifest ) && is_file( $manifest ) &&
            (int) $source_stat['size'] === (int) $proof['size_bytes'] && (int) $source_stat['mtime'] === (int) $proof['file_mtime'] &&
            (int) $source_stat['ctime'] === (int) $proof['file_ctime'] && (int) $source_stat['ino'] === (int) $proof['file_inode'] &&
            (int) $source_stat['dev'] === (int) $proof['file_device'] && (int) $manifest_stat['size'] === (int) $proof['manifest_size_bytes'] &&
            (int) $manifest_stat['mtime'] === (int) $proof['manifest_mtime'] && (int) $manifest_stat['ctime'] === (int) $proof['manifest_ctime'] &&
            (int) $manifest_stat['ino'] === (int) $proof['manifest_inode'] && (int) $manifest_stat['dev'] === (int) $proof['manifest_device'];
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
    public static function enqueue_offsite_upload( $archive, $allow_unencrypted = false, $delete_after_upload = false, $idempotency_key = null ) {
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
        if ( $idempotency_key === null ) {
            $idempotency_key = wp_generate_password( 32, false, false );
        }
        $id = Trasloco_Job_Store::create( 'transfer', $payload, $idempotency_key );
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

    /** Process one authenticated archive-encryption batch and publish atomically. */
    private static function crypto_batch( $id, $owner, $payload ) {
        require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-offline-recovery.php';
        if ( ! isset( $payload['operation'], $payload['archive'] ) || count( $payload ) !== 2 ||
            ! in_array( $payload['operation'], array( 'encrypt', 'decrypt' ), true ) || ! is_string( $payload['archive'] ) ) {
            throw new RuntimeException( 'Invalid encryption job.' );
        }
        $operation = $payload['operation'];
        $archive = $payload['archive'];
        if ( $operation === 'encrypt' ) {
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $archive ) || strpos( $archive, '..' ) !== false ) {
                throw new RuntimeException( 'Invalid backup reference.' );
            }
            $source = trasloco_backup_path( array( 'archive' => $archive ) );
            if ( ! self::crypto_source_has_verification_proof( $archive, $source ) ) {
                throw new RuntimeException( 'Source backup verification is no longer current.' );
            }
            $target = trasloco_encrypted_backup_path( $archive . '.enc' );
            $output_name = $archive . '.enc';
        } else {
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress\.enc$/D', $archive ) || strpos( $archive, '..' ) !== false ) {
                throw new RuntimeException( 'Invalid encrypted backup reference.' );
            }
            $source = trasloco_encrypted_backup_path( $archive );
            $output_name = substr( $archive, 0, -strlen( '.wpress.enc' ) ) . '-decrypted.wpress';
            $target = trasloco_backup_path( array( 'archive' => $output_name ) );
        }
        // Offline_Recovery validates the completed plaintext by its .wpress
        // suffix before publication; the leading dot keeps staging private.
        $temp = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.trasloco-crypto-' . (int) $id . '.part.wpress';
        if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) || is_link( TRASLOCO_BACKUPS_PATH ) ) {
            throw new RuntimeException( 'Private backup storage is unavailable.' );
        }
        $passphrase = defined( 'TRASLOCO_ARCHIVE_PASSPHRASE' ) ? TRASLOCO_ARCHIVE_PASSPHRASE : getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
        if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
            throw new RuntimeException( 'Archive encryption is not configured.' );
        }
        $previous = Trasloco_Job_Store::get_internal( $id );
        $state = is_array( $previous ) && isset( $previous['checkpoint']['crypto_state'] ) &&
            is_array( $previous['checkpoint']['crypto_state'] ) ? $previous['checkpoint']['crypto_state'] : null;
        if ( is_file( $target ) || is_link( $target ) || is_file( Trasloco_Integrity::manifest_path( $target ) ) ) {
            if ( ! $state || empty( $state['complete'] ) || is_link( $target ) || ! is_file( $target ) ||
                ! is_file( $temp ) || is_link( $temp ) ) {
                throw new RuntimeException( 'The output archive already exists.' );
            }
            $temp_stat = @lstat( $temp ); $target_stat = @lstat( $target );
            if ( ! is_array( $temp_stat ) || ! is_array( $target_stat ) ||
                $temp_stat['ino'] !== $target_stat['ino'] || $temp_stat['dev'] !== $target_stat['dev'] ) {
                throw new RuntimeException( 'The output archive belongs to another operation.' );
            }
        }
        $state = Trasloco_Encryption::resumable_step( $operation, $source, $temp, $passphrase, $state, 4 );
        $progress = $state['total_bytes'] > 0
            ? min( 99, 5 + (int) floor( ( $state['progress_bytes'] / $state['total_bytes'] ) * 90 ) ) : 95;
        $checkpoint = array(
            'phase' => $state['complete'] ? 'publishing' : $operation,
            'archive' => $archive,
            'bytes' => (int) $state['progress_bytes'],
            'total_bytes' => (int) $state['total_bytes'],
            'crypto_state' => $state,
        );
        if ( ! $state['complete'] ) {
            if ( ! Trasloco_Job_Store::yield_crypto( $id, $owner, $progress, $checkpoint ) ) {
                throw new RuntimeException( 'Encryption cancelled or worker lease lost.' );
            }
            return array( 'status' => 'pending', 'progress' => $progress );
        }
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 99, $checkpoint ) ) {
            throw new RuntimeException( 'Encryption cancelled or worker lease lost before publication.' );
        }
        if ( $operation === 'decrypt' ) {
            Trasloco_Offline_Recovery::inspect( $temp );
        }
        $published_here = false;
        if ( ! file_exists( $target ) && ! @link( $temp, $target ) ) {
            throw new RuntimeException( 'Cannot publish the completed archive without replacing an existing file.' );
        } elseif ( file_exists( $target ) ) {
            $temp_stat = @lstat( $temp ); $target_stat = @lstat( $target );
            $published_here = is_array( $temp_stat ) && is_array( $target_stat ) &&
                $temp_stat['ino'] === $target_stat['ino'] && $temp_stat['dev'] === $target_stat['dev'];
        }
        try {
            if ( $operation === 'encrypt' ) {
                Trasloco_Integrity::create( $target );
            } else {
                Trasloco_Integrity::create_chunked( $target );
            }
        } catch ( Throwable $error ) {
            if ( $published_here ) {
                @unlink( $target );
                @unlink( Trasloco_Integrity::manifest_path( $target ) );
            }
            throw $error;
        }
        if ( ! Trasloco_Job_Store::finish( $id, $owner ) ) {
            $latest = Trasloco_Job_Store::get_internal( $id );
            if ( is_array( $latest ) && $latest['state'] === 'cancelled' ) {
                $temp_stat = @lstat( $temp ); $target_stat = @lstat( $target );
                if ( is_array( $temp_stat ) && is_array( $target_stat ) &&
                    $temp_stat['ino'] === $target_stat['ino'] && $temp_stat['dev'] === $target_stat['dev'] ) {
                    @unlink( $target );
                    @unlink( Trasloco_Integrity::manifest_path( $target ) );
                }
            }
            throw new RuntimeException( 'Encryption cancelled or worker lease lost before completion.' );
        }
        @unlink( $temp );
        return array( 'status' => 'completed', 'archive' => $output_name );
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

    /** Keep safety-point callers synchronous while sharing the resumable worker stages. */
    public static function run_backup_to_completion( $id, $max_steps = 10000 ) {
        $result = array( 'status' => 'invalid' );
        for ( $step = 0; $step < $max_steps; $step++ ) {
            $result = self::run( $id );
            if ( ! isset( $result['status'] ) || $result['status'] !== 'pending' ) {
                return $result;
            }
        }
        return array( 'status' => 'pending', 'progress' => isset( $result['progress'] ) ? $result['progress'] : 0 );
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

    /** Run one resumable export stage and preserve its last durable position. */
    private static function backup( $id, $owner, $payload, $saved = array() ) {
        $is_recovery = ! empty( $payload['recovery_point'] );
        $incremental_enabled = ! $is_recovery && ! empty( $payload['incremental_snapshots'] );
        $incremental_resume = is_array( $saved ) && isset( $saved['phase'] ) && $saved['phase'] === 'incremental';
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
        trasloco_setup_environment();
        $checkpoint = is_array( $saved ) && isset( $saved['phase'] ) && $saved['phase'] === 'export'
            ? $saved : array();
        $finalizing = is_array( $saved ) && isset( $saved['phase'], $saved['archive'] ) &&
            in_array( $saved['phase'], array( 'incremental', 'retention', 'recovery' ), true ) &&
            is_string( $saved['archive'] ) && preg_match( '/^[A-Za-z0-9_.-]+\.wpress$/D', $saved['archive'] );
        $params = $finalizing
            ? array( 'archive' => $saved['archive'] )
            : ( isset( $checkpoint['params'] ) && is_array( $checkpoint['params'] )
                ? $checkpoint['params'] : ( $is_recovery ? array( 'archive' => 'recovery-' . trasloco_archive_file() ) : array() ) );
        if ( $finalizing && isset( $saved['storage'] ) && is_string( $saved['storage'] ) &&
            preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $saved['storage'] ) ) {
            $params['storage'] = $saved['storage'];
        }
        $priority = $finalizing ? 300 : ( isset( $checkpoint['priority'] ) ? (int) $checkpoint['priority'] : 5 );
        if ( ! $finalizing && ! empty( $checkpoint['artifacts'] ) ) {
            self::restore_backup_artifacts( $params, $checkpoint['artifacts'], $priority >= 250 );
        }
        // Suppress nested export progress while the import displays its
        // recovery-point stage; do not discard the import's own status.
        if ( $is_recovery ) {
            $GLOBALS['trasloco_recovery_export_active'] = true;
        }
        remove_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );
        try {
            if ( $finalizing ) {
                $next_priority = 300;
                $complete = true;
            } else {
                list( $params, $next_priority, $complete ) = self::run_backup_export_stage( $params, $priority );
                if ( $priority <= 5 && ! empty( $params['archive'] ) &&
                    ( file_exists( trasloco_backup_path( $params ) ) || is_link( trasloco_backup_path( $params ) ) ) ) {
                    throw new RuntimeException( 'A backup with this name already exists.' );
                }
            }
        } finally {
            unset( $GLOBALS['trasloco_recovery_export_active'] );
            add_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );
        }
        if ( ! $complete ) {
            $checkpoint = array( 'phase' => 'export', 'priority' => $next_priority,
                'params' => $params, 'artifacts' => self::checkpoint_backup_artifacts( $params ) );
            $progress = min( 89, 10 + max( 0, min( 79, (int) floor( ( $next_priority / 300 ) * 80 ) ) ) );
            if ( ! Trasloco_Job_Store::yield_backup( $id, $owner, $progress, $checkpoint ) ) {
                throw new RuntimeException( 'Backup checkpoint could not be committed.' );
            }
            return array( 'status' => 'pending', 'progress' => $progress );
        }
        if ( empty( $params['archive'] ) ) {
            throw new RuntimeException( 'Export did not return an archive.' );
        }
        $archive = trasloco_backup_path( $params );
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        if ( $incremental_resume && ! $incremental_enabled ) {
            throw new RuntimeException( 'Incremental backup checkpoint does not match its job payload.' );
        }
        if ( $incremental_enabled ) {
            require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
            if ( $incremental_resume ) {
                $manifest = Trasloco_Integrity::chunked_manifest( $archive );
                if ( ! is_array( $manifest ) || ! isset( $saved['integrity_sha256'], $saved['manifest_sha256'], $saved['size_bytes'] ) ||
                    ! hash_equals( $saved['integrity_sha256'], $manifest['sha256'] ) ||
                    ! hash_equals( $saved['manifest_sha256'], $manifest['_manifest_sha256'] ) ||
                    $saved['size_bytes'] !== $manifest['size_bytes'] ) {
                    throw new RuntimeException( 'Incremental capture manifest changed while the backup was pending.' );
                }
            } else {
                $integrity = Trasloco_Integrity::verify( $archive );
                if ( $integrity['status'] !== 'verified' ) {
                    throw new RuntimeException( 'Backup did not pass integrity verification.' );
                }
                $manifest = Trasloco_Integrity::chunked_manifest( $archive );
                if ( $manifest === null ) {
                    Trasloco_Integrity::create_chunked( $archive );
                    $integrity = Trasloco_Integrity::verify( $archive );
                    $manifest = Trasloco_Integrity::chunked_manifest( $archive );
                    if ( $integrity['status'] !== 'verified' || ! is_array( $manifest ) ) {
                        throw new RuntimeException( 'Could not prepare chunk checksums for incremental capture.' );
                    }
                }
            }
            if ( ! $incremental_resume && ! Trasloco_Job_Store::checkpoint( $id, $owner, 90, array(
                'phase' => 'incremental', 'archive' => $params['archive'],
                'storage' => isset( $params['storage'] ) ? $params['storage'] : '',
                'integrity_sha256' => $manifest['sha256'], 'manifest_sha256' => $manifest['_manifest_sha256'],
                'size_bytes' => $manifest['size_bytes'],
            ) ) ) {
                throw new RuntimeException( 'Backup cancelled before incremental capture.' );
            }
            $store = new Trasloco_Incremental_Store(
                rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental'
            );
            $capture = $store->capture_batch( $archive, $manifest['sha256'], $manifest['_manifest_sha256'],
                $manifest['chunks'], (int) $id, 8 );
            if ( $capture['status'] === 'pending' ) {
                $ratio = $capture['size_bytes'] > 0 ? $capture['bytes'] / $capture['size_bytes'] : 1;
                $progress = min( 98, 90 + (int) floor( 8 * $ratio ) );
                $next = array( 'phase' => 'incremental', 'archive' => $params['archive'],
                    'storage' => isset( $params['storage'] ) ? $params['storage'] : '',
                    'integrity_sha256' => $manifest['sha256'], 'manifest_sha256' => $manifest['_manifest_sha256'],
                    'size_bytes' => $manifest['size_bytes'] );
                if ( ! Trasloco_Job_Store::yield_backup( $id, $owner, $progress, $next ) ) {
                    throw new RuntimeException( 'Incremental backup checkpoint could not be committed.' );
                }
                return array( 'status' => 'pending', 'progress' => $progress );
            }
        } elseif ( ! $incremental_resume && Trasloco_Integrity::verify( $archive )['status'] !== 'verified' ) {
            throw new RuntimeException( 'Backup did not pass integrity verification.' );
        }
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 95, array( 'phase' => $is_recovery ? 'recovery' : 'retention',
            'archive' => $params['archive'], 'storage' => isset( $params['storage'] ) ? $params['storage'] : '' ) ) ) {
            throw new RuntimeException( 'Backup cancelled before retention.' );
        }
        if ( $is_recovery ) {
            Trasloco_Directory::delete( trasloco_storage_path( $params ) );
            return array( 'status' => 'completed', 'archive' => $params['archive'] );
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
                if ( file_exists( $encrypted_path ) || is_link( $encrypted_path ) ||
                    file_exists( $encrypted_manifest ) || is_link( $encrypted_manifest ) ) {
                    if ( is_link( $encrypted_path ) || is_link( $encrypted_manifest ) ||
                        ! is_file( $encrypted_path ) || ! is_file( $encrypted_manifest ) ||
                        Trasloco_Integrity::verify( $encrypted_path )['status'] !== 'verified' ) {
                        throw new RuntimeException( 'Existing encrypted backup could not be verified.' );
                    }
                } else {
                    require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
                    Trasloco_Encryption::encrypt( $archive, $encrypted_path, $passphrase );
                    $encrypted_created = true;
                    Trasloco_Integrity::create_chunked( $encrypted_path );
                }
                $upload_key = hash( 'sha256', 'scheduled-backup-offsite|' . (int) $id . '|' . $encrypted_name );
                $upload_id = self::enqueue_offsite_upload( $encrypted_name, false, true, $upload_key );
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
        if ( $incremental_enabled ) {
            $retained_archives = array( basename( $params['archive'] ) => true );
            foreach ( array_slice( $prior, 0, $keep - 1 ) as $entry ) {
                $retained_archives[ basename( $entry['filename'] ) ] = true;
            }
            $snapshot_keep = array();
            foreach ( $store->list_snapshots() as $snapshot ) {
                if ( Trasloco_Backups::is_recovery_point( $snapshot['archive'] ) || isset( $retained_archives[ $snapshot['archive'] ] ) ) {
                    $snapshot_keep[] = $snapshot['archive'];
                }
            }
            if ( empty( $snapshot_keep ) ) {
                throw new RuntimeException( 'Incremental retention could not preserve the new snapshot.' );
            }
            $store->prune( $snapshot_keep );
        }
        // Always protect the newly verified archive, even when creation
        // timestamps tie and sort order is not deterministic.
        foreach ( array_slice( $prior, $keep - 1 ) as $entry ) {
            $model->delete_file( $entry['filename'] );
        }
        Trasloco_Directory::delete( trasloco_storage_path( $params ) );
        return array( 'status' => 'completed', 'archive' => $params['archive'] );
    }

    /** Execute all callbacks registered at one export priority. */
    private static function run_backup_export_stage( $params, $priority ) {
        global $wp_filter;
        if ( ! isset( $wp_filter['trasloco_export'] ) ) {
            throw new RuntimeException( 'Export pipeline is unavailable.' );
        }
        $filters = $wp_filter['trasloco_export'];
        if ( isset( $filters->callbacks ) ) {
            $filters = $filters->callbacks;
        }
        if ( ! is_array( $filters ) ) {
            throw new RuntimeException( 'Export pipeline is invalid.' );
        }
        ksort( $filters, SORT_NUMERIC );
        $priority = (int) $priority;
        if ( ! isset( $filters[ $priority ] ) || $priority >= 300 ) {
            foreach ( array_keys( $filters ) as $candidate ) {
                if ( (int) $candidate >= $priority && (int) $candidate < 300 ) {
                    $priority = (int) $candidate;
                    break;
                }
            }
        }
        if ( ! isset( $filters[ $priority ] ) || $priority >= 300 ) {
            return array( $params, $priority, true );
        }
        foreach ( $filters[ $priority ] as $hook ) {
            if ( is_string( $hook['function'] ) && $hook['function'] === 'Trasloco_Export_Download::execute' ) {
                $published = trasloco_backup_path( $params );
                if ( is_link( $published ) ) {
                    throw new RuntimeException( 'Published backup is unsafe.' );
                }
                if ( is_file( $published ) ) {
                    require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
                    if ( ! is_file( Trasloco_Integrity::manifest_path( $published ) ) ) {
                        Trasloco_Integrity::create_chunked( $published );
                    }
                    if ( Trasloco_Integrity::verify( $published )['status'] !== 'verified' ) {
                        throw new RuntimeException( 'Published backup could not be verified after interruption.' );
                    }
                    continue;
                }
            }
            $params = call_user_func_array( $hook['function'], array( $params ) );
            if ( ! is_array( $params ) ) {
                throw new RuntimeException( 'An export stage returned invalid state.' );
            }
        }
        if ( isset( $params['completed'] ) && $params['completed'] === false ) {
            return array( $params, $priority, false );
        }
        $next = $priority;
        foreach ( array_keys( $filters ) as $candidate ) {
            if ( (int) $candidate > $priority && (int) $candidate < 300 ) {
                $next = (int) $candidate;
                break;
            }
        }
        return array( $params, $next, $next === $priority );
    }

    /** Flush private staging files before publishing their offsets. */
    private static function checkpoint_backup_artifacts( $params ) {
        $sizes = array();
        foreach ( self::backup_artifact_paths( $params ) as $name => $path ) {
            clearstatcache( true, $path );
            if ( ! file_exists( $path ) ) {
                continue;
            }
            if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
                throw new RuntimeException( 'Backup staging artifact is unsafe.' );
            }
            $handle = @fopen( $path, 'r+b' );
            if ( ! is_resource( $handle ) ) {
                throw new RuntimeException( 'Backup staging artifact cannot be flushed.' );
            }
            if ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) {
                fclose( $handle );
                throw new RuntimeException( 'Backup staging artifact could not be synchronized.' );
            }
            fclose( $handle );
            $sizes[ $name ] = (int) filesize( $path );
        }
        return $sizes;
    }

    /** Discard bytes appended after the last committed export stage. */
    private static function restore_backup_artifacts( $params, $sizes, $allow_published_archive = false ) {
        $paths = self::backup_artifact_paths( $params );
        foreach ( $sizes as $name => $size ) {
            if ( ! isset( $paths[ $name ] ) || ! is_int( $size ) || $size < 0 ) {
                throw new RuntimeException( 'Backup checkpoint is invalid.' );
            }
            $path = $paths[ $name ];
            clearstatcache( true, $path );
            if ( $name === 'archive' && $allow_published_archive && ! file_exists( $path ) &&
                is_file( trasloco_backup_path( $params ) ) && ! is_link( trasloco_backup_path( $params ) ) ) {
                continue;
            }
            if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) < $size ) {
                throw new RuntimeException( 'Backup staging artifact changed after interruption.' );
            }
            $handle = @fopen( $path, 'c+b' );
            if ( ! is_resource( $handle ) || ! @ftruncate( $handle, $size ) ) {
                if ( is_resource( $handle ) ) { fclose( $handle ); }
                throw new RuntimeException( 'Backup staging artifact could not be resumed safely.' );
            }
            if ( function_exists( 'fsync' ) ) { @fsync( $handle ); }
            fclose( $handle );
        }
    }

    private static function backup_artifact_paths( $params ) {
        if ( empty( $params['storage'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $params['storage'] ) ) {
            throw new RuntimeException( 'Backup storage checkpoint is invalid.' );
        }
        $storage = TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
        if ( is_link( $storage ) || ( file_exists( $storage ) && ! is_dir( $storage ) ) ) {
            throw new RuntimeException( 'Backup storage checkpoint is unsafe.' );
        }
        $paths = array();
        if ( isset( $params['archive'] ) &&
            ! preg_match( '/^[A-Za-z0-9_.-]+\.wpress$/D', $params['archive'] ) ) {
            throw new RuntimeException( 'Backup archive checkpoint is invalid.' );
        }
        if ( isset( $params['archive'] ) ) {
            $paths['archive'] = $storage . DIRECTORY_SEPARATOR . $params['archive'];
        }
        $paths['database'] = $storage . DIRECTORY_SEPARATOR . TRASLOCO_DATABASE_NAME;
        $paths['filemap'] = $storage . DIRECTORY_SEPARATOR . TRASLOCO_FILEMAP_NAME;
        $paths['package'] = $storage . DIRECTORY_SEPARATOR . TRASLOCO_PACKAGE_NAME;
        return $paths;
    }


    /** Persist a small file-identity proof so the archive list can show fresh results. */
    private static function verification_proof( $archive, $result ) {
        clearstatcache( true, $archive );
        $archive_stat = @lstat( $archive );
        $manifest = Trasloco_Integrity::manifest_path( $archive );
        clearstatcache( true, $manifest );
        $manifest_stat = @lstat( $manifest );
        if ( ! is_array( $archive_stat ) || is_link( $archive ) || ! is_file( $archive ) ||
            ! is_array( $manifest_stat ) || is_link( $manifest ) || ! is_file( $manifest ) ||
            (int) $archive_stat['size'] !== (int) $result['size_bytes'] ) {
            throw new RuntimeException( 'Archive changed after verification.' );
        }
        return array(
            'phase' => 'verified',
            'size_bytes' => (int) $archive_stat['size'],
            'file_mtime' => (int) $archive_stat['mtime'],
            'file_ctime' => (int) $archive_stat['ctime'],
            'file_inode' => (int) $archive_stat['ino'],
            'file_device' => (int) $archive_stat['dev'],
            'manifest_size_bytes' => (int) $manifest_stat['size'],
            'manifest_mtime' => (int) $manifest_stat['mtime'],
            'manifest_ctime' => (int) $manifest_stat['ctime'],
            'manifest_inode' => (int) $manifest_stat['ino'],
            'manifest_device' => (int) $manifest_stat['dev'],
        );
    }

    private static function finish_verification( $id, $owner, $archive, $result ) {
        $proof = self::verification_proof( $archive, $result );
        if ( ! Trasloco_Job_Store::checkpoint( $id, $owner, 99, $proof ) ) {
            return false;
        }
        return Trasloco_Job_Store::finish( $id, $owner );
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
            if ( ! self::finish_verification( $id, $owner, $archive, array(
                'size_bytes' => $size, 'sha256' => $manifest['sha256'],
            ) ) ) {
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
        if ( ! $record || ! in_array( $record['kind'], array( 'verify', 'backup', 'transfer', 'crypto' ), true ) ||
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
            if ( $record['kind'] === 'crypto' ) {
                $result = self::crypto_batch( $id, $owner, $record['payload'] );
                if ( isset( $result['status'] ) && $result['status'] === 'pending' ) {
                    self::schedule( $id );
                }
                return $result;
            }
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
                    $internal = Trasloco_Job_Store::get_internal( $id );
                    $result = self::backup( $id, $owner, $record['payload'],
                        is_array( $internal ) ? $internal['checkpoint'] : array() );
                } finally {
                    unset( $GLOBALS['trasloco_active_backup_job'] );
                    unset( $GLOBALS['trasloco_active_export_lock'] );
                    Trasloco_Export_Lock::release( $backup_lock, $backup_lock_owner );
                }
                if ( isset( $result['status'] ) && $result['status'] === 'pending' ) {
                    self::schedule( $id );
                    return $result;
                }
                $name = $result['archive'];
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
            if ( ! self::finish_verification( $id, $owner, $archive, $result ) ) {
                return array( 'status' => 'not_claimed' );
            }
            return array( 'status' => 'completed', 'archive' => $filename );
        } catch ( Throwable $e ) {
            // Generic error only; never log any secret-bearing payload or path.
            // Provider exceptions can include URLs or request details. Keep
            // the durable record secret-free and useful without those values.
            $failure = $record['kind'] === 'transfer' ? 'Offsite transfer failed; inspect provider availability and job history.' :
                ( $record['kind'] === 'crypto' ? 'Archive encryption or decryption failed; check its integrity and configuration.' : $e->getMessage() );
            $failed = Trasloco_Job_Store::fail( $id, $owner, $failure );
            if ( $record['kind'] === 'crypto' ) {
                $latest = Trasloco_Job_Store::get_internal( $id );
                if ( $failed || ( is_array( $latest ) && in_array( $latest['state'], array( 'cancelled','failed' ), true ) ) ) {
                    $partial = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.trasloco-crypto-' . $id . '.part.wpress';
                    if ( is_file( $partial ) && ! is_link( $partial ) ) { @unlink( $partial ); }
                }
            }
            if ( $record['kind'] === 'backup' ) {
                $internal = Trasloco_Job_Store::get_internal( $id );
                if ( is_array( $internal ) && in_array( $internal['state'], array( 'cancelled', 'failed' ), true ) ) {
                    try {
                        require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
                        ( new Trasloco_Incremental_Store(
                            rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental'
                        ) )->discard_capture( (int) $id );
                    } catch ( Throwable $ignored ) {}
                }
                if ( $failed && is_array( $internal ) && isset( $internal['checkpoint']['phase'], $internal['checkpoint']['params']['storage'] ) &&
                    $internal['checkpoint']['phase'] === 'export' &&
                    preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $internal['checkpoint']['params']['storage'] ) ) {
                    $workspace = TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . $internal['checkpoint']['params']['storage'];
                    if ( is_dir( $workspace ) && ! is_link( $workspace ) ) {
                        try { Trasloco_Directory::delete( $workspace ); } catch ( Throwable $ignored ) {}
                    }
                }
            }
            if ( $record['kind'] === 'backup' && empty( $record['payload']['recovery_point'] ) ) {
                do_action( 'trasloco_scheduled_backup_failed', $id );
            } elseif ( $record['kind'] === 'transfer' && $failed ) {
                do_action( 'trasloco_offsite_transfer_failed', $id );
            }
            return array( 'status' => 'failed' );
        }
    }
}
