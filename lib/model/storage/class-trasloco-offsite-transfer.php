<?php
/**
 * Verified, non-overwriting transfer between local backup files and a
 * configured storage adapter. Plaintext upload always needs explicit consent.
 */
class Trasloco_Offsite_Transfer {
    const MAX_INVENTORY_ITEMS = 10000;
    const DOWNLOAD_CHUNK_BYTES = 1048576;

    private $remote;
    private $local_root;

    public function __construct( $remote, $local_root ) {
        if ( ! $remote instanceof Trasloco_Storage_Adapter ) {
            throw new InvalidArgumentException( 'A supported remote storage adapter is required.' );
        }
        // Reuse the local adapter's directory validation and canonicalization.
        new Trasloco_Local_Storage( $local_root );
        $root = realpath( $local_root );
        if ( $root === false ) {
            throw new InvalidArgumentException( 'Invalid local backup directory.' );
        }
        $this->remote = $remote;
        $this->local_root = $root;
    }

    private function archive_name( $archive ) {
        // Reserve room for the longest supported integrity sidecar key.
        if ( ! is_string( $archive ) || strlen( $archive ) > 206 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $archive ) ||
            strpos( $archive, '..' ) !== false ) {
            throw new InvalidArgumentException( 'Invalid backup archive name.' );
        }
        return $archive;
    }

    private function file_digest( $path ) {
        if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) {
            throw new RuntimeException( 'Backup or integrity manifest is missing or unsafe.' );
        }
        clearstatcache( true, $path );
        $size = filesize( $path );
        $digest = hash_file( 'sha256', $path );
        if ( $size === false || $digest === false ) {
            throw new RuntimeException( 'Unable to verify backup bytes.' );
        }
        return array( (int) $size, $digest );
    }

    private function validate_manifest_file( $manifest ) {
        if ( ! is_file( $manifest ) || is_link( $manifest ) || ! is_readable( $manifest ) ) {
            throw new RuntimeException( 'Backup integrity manifest is missing or unsafe.' );
        }
        clearstatcache( true, $manifest );
        $size = filesize( $manifest );
        if ( $size === false || $size > Trasloco_Integrity::MAX_MANIFEST_BYTES ) {
            throw new RuntimeException( 'Backup integrity manifest exceeds the allowed size.' );
        }
        return $manifest;
    }

    private function require_bounded_manifest( $path ) {
        return $this->validate_manifest_file( Trasloco_Integrity::manifest_path( $path ) );
    }

    private function manifest_archive_data( $manifest, $archive ) {
        $this->validate_manifest_file( $manifest );
        $body = @file_get_contents( $manifest );
        $data = is_string( $body ) ? json_decode( $body, true ) : null;
        if ( ! is_array( $data ) ) {
            throw new RuntimeException( 'Remote integrity manifest is invalid.' );
        }
        if ( isset( $data['manifest_version'] ) && in_array( $data['manifest_version'], array( 1, 2 ), true ) &&
            isset( $data['algorithm'], $data['size_bytes'], $data['sha256'] ) && $data['algorithm'] === 'sha256' &&
            is_int( $data['size_bytes'] ) && $data['size_bytes'] >= 0 && is_string( $data['sha256'] ) &&
            preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            return array( 'bytes' => $data['size_bytes'], 'sha256' => $data['sha256'] );
        }
        if ( isset( $data['schema'], $data['format'], $data['archive'], $data['bytes'], $data['sha256'] ) &&
            $data['schema'] === 1 && $data['format'] === 'wpress' && $data['archive'] === $archive &&
            is_int( $data['bytes'] ) && $data['bytes'] >= 0 && is_string( $data['sha256'] ) &&
            preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            return array( 'bytes' => $data['bytes'], 'sha256' => $data['sha256'] );
        }
        throw new RuntimeException( 'Remote integrity manifest does not describe a supported archive.' );
    }

    private function stream_digest( $stream ) {
        if ( ! is_resource( $stream ) ) {
            throw new InvalidArgumentException( 'Storage returned an invalid stream.' );
        }
        $hash = hash_init( 'sha256' );
        $bytes = 0;
        while ( ! feof( $stream ) ) {
            $part = fread( $stream, 1048576 );
            if ( $part === false || ( $part === '' && ! feof( $stream ) ) ) {
                throw new RuntimeException( 'Storage stream ended unexpectedly.' );
            }
            if ( $part !== '' ) {
                hash_update( $hash, $part );
                $bytes += strlen( $part );
            }
        }
        return array( $bytes, hash_final( $hash ) );
    }

    private function retry_transient( $operation ) {
        for ( $attempt = 1; ; ++$attempt ) {
            try {
                return $operation();
            } catch ( Trasloco_Storage_Transient_Exception $error ) {
                if ( $attempt >= 3 ) {
                    throw $error;
                }
                usleep( 250000 * $attempt );
            }
        }
    }

    private function remote_matches( $key, $expected ) {
        if ( ! $this->remote->exists( $key ) ) {
            return false;
        }
        $stream = $this->remote->open_read( $key, $expected[0] );
        try {
            $actual = $this->stream_digest( $stream );
        } finally {
            fclose( $stream );
        }
        if ( $actual !== $expected ) {
            throw new RuntimeException( 'Remote object already exists with different content: ' . $key );
        }
        return true;
    }

    private function upload_one_attempt( $key, $path, $progress_callback = null ) {
        $expected = $this->file_digest( $path );
        if ( $this->remote_matches( $key, $expected ) ) {
            return false;
        }
        if ( $this->remote instanceof Trasloco_Resumable_Storage_Adapter &&
            preg_match( '/\.wpress(?:\.enc)?$/D', $key ) ) {
            $published = $this->remote->put_resumable( $key, $path, $expected[0], $expected[1], $progress_callback );
            if ( ! $this->remote_matches( $key, $expected ) ) {
                throw new RuntimeException( 'Remote upload did not publish the complete object.' );
            }
            return (bool) $published;
        }
        $input = @fopen( $path, 'rb' );
        if ( $input === false ) {
            throw new RuntimeException( 'Unable to open local backup for offsite upload.' );
        }
        try {
            $stat = fstat( $input );
            $path_stat = @lstat( $path );
            if ( ! is_array( $stat ) || ! is_array( $path_stat ) ||
                is_link( $path ) || $stat['dev'] !== $path_stat['dev'] || $stat['ino'] !== $path_stat['ino'] ) {
                throw new RuntimeException( 'Local backup changed before offsite upload.' );
            }
            try {
                $this->remote->put_stream( $key, $input );
            } catch ( Throwable $error ) {
                // A connection can fail after the provider committed the PUT.
                // Treat it as success only after reading back exact bytes.
                if ( ! $this->remote_matches( $key, $expected ) ) {
                    throw $error;
                }
                return true;
            }
        } finally {
            fclose( $input );
        }
        if ( ! $this->remote_matches( $key, $expected ) ) {
            throw new RuntimeException( 'Remote upload did not publish the complete object.' );
        }
        return true;
    }

    private function upload_one( $key, $path, $progress_callback = null ) {
        return $this->retry_transient( function () use ( $key, $path, $progress_callback ) {
            return $this->upload_one_attempt( $key, $path, $progress_callback );
        } );
    }

    public function upload( $archive, $allow_unencrypted = false, $progress_callback = null ) {
        if ( $progress_callback !== null && ! is_callable( $progress_callback ) ) {
            throw new InvalidArgumentException( 'Invalid offsite upload progress callback.' );
        }
        $archive = $this->archive_name( $archive );
        $encrypted = substr( $archive, -4 ) === '.enc';
        if ( ! $encrypted && ! $allow_unencrypted ) {
            throw new RuntimeException( 'Plaintext backups require explicit --allow-unencrypted consent.' );
        }
        $path = $this->local_root . DIRECTORY_SEPARATOR . $archive;
        $manifest = $this->require_bounded_manifest( $path );
        if ( Trasloco_Integrity::verify( $path )['status'] !== 'verified' ) {
            throw new RuntimeException( 'Only a backup with a verified integrity manifest can be uploaded.' );
        }
        $archive_uploaded = $this->upload_one( $archive, $path, $progress_callback );
        // Publish the manifest last. Readers must treat it as the completeness
        // marker for a pair interrupted between the archive and sidecar writes.
        $manifest_uploaded = $this->upload_one( $archive . '.manifest.json', $manifest );
        return array(
            'status' => $archive_uploaded || $manifest_uploaded ? 'uploaded' : 'already_present',
            'archive' => $archive,
            'bytes' => $this->file_digest( $path )[0],
            'encrypted' => $encrypted,
        );
    }

    /**
     * Return a bounded summary of remote archive/manifest pairs without
     * downloading their contents. A complete pair still requires a transfer
     * verification before it can be trusted for restore.
     */
    public function inventory_status() {
        $keys = $this->remote->enumerate();
        if ( ! is_array( $keys ) || count( $keys ) > self::MAX_INVENTORY_ITEMS ) {
            throw new RuntimeException( 'Remote storage returned an invalid or oversized inventory.' );
        }
        $archives = array();
        $manifests = array();
        foreach ( $keys as $key ) {
            if ( ! is_string( $key ) ) {
                throw new RuntimeException( 'Remote storage returned an invalid inventory entry.' );
            }
            if ( preg_match( '/^(.*\.wpress(?:\.enc)?)\.manifest\.json$/D', $key, $match ) ) {
                $archive = $this->archive_name( $match[1] );
                $manifests[ $archive ] = true;
            } elseif ( preg_match( '/\.wpress(?:\.enc)?$/D', $key ) ) {
                $archive = $this->archive_name( $key );
                $archives[ $archive ] = true;
            }
        }

        $names = array_values( array_unique( array_merge( array_keys( $archives ), array_keys( $manifests ) ) ) );
        sort( $names, SORT_STRING );
        $items = array();
        $complete = 0;
        foreach ( $names as $name ) {
            $has_archive = isset( $archives[ $name ] );
            $has_manifest = isset( $manifests[ $name ] );
            $status = $has_archive && $has_manifest ? 'complete' : ( $has_archive ? 'missing_manifest' : 'missing_archive' );
            if ( $status === 'complete' ) {
                ++$complete;
            }
            $items[] = array( 'archive' => $name, 'status' => $status );
        }

        return array(
            'status' => count( $items ) === $complete ? 'healthy' : 'incomplete',
            'archives' => $items,
            'complete' => $complete,
            'incomplete' => count( $items ) - $complete,
        );
    }

    private function download_one_once( $key, $destination, $max_bytes ) {
        $stream = $this->remote->open_read( $key, $max_bytes );
        $output = @fopen( $destination, 'wb' );
        if ( $output === false ) {
            fclose( $stream );
            throw new RuntimeException( 'Unable to stage the offsite download privately.' );
        }
        try {
            @chmod( $destination, 0600 );
            $bytes = 0;
            while ( ! feof( $stream ) ) {
                $part = fread( $stream, 1048576 );
                if ( $part === false || ( $part === '' && ! feof( $stream ) ) ) {
                    throw new RuntimeException( 'Offsite download ended before the stream was complete.' );
                }
                $length = strlen( $part );
                if ( $bytes + $length > $max_bytes ) {
                    throw new RuntimeException( 'Offsite object exceeds its verified size.' );
                }
                $offset = 0;
                while ( $offset < $length ) {
                    $written = @fwrite( $output, substr( $part, $offset ) );
                    if ( $written === false || $written === 0 ) {
                        throw new RuntimeException( 'Unable to write the offsite staging file.' );
                    }
                    $offset += $written;
                }
                $bytes += $length;
            }
            if ( ! fflush( $output ) ) {
                throw new RuntimeException( 'Offsite download ended before the stream was complete.' );
            }
            return $bytes;
        } finally {
            fclose( $stream );
            fclose( $output );
        }
    }

    private function download_one( $key, $destination, $max_bytes ) {
        return $this->retry_transient( function () use ( $key, $destination, $max_bytes ) {
            return $this->download_one_once( $key, $destination, $max_bytes );
        } );
    }

    private function resume_directory( $archive, $digest ) {
        $directory = $this->local_root . DIRECTORY_SEPARATOR . '.trasloco-offsite-' .
            hash( 'sha256', $this->local_root . '|' . $archive . '|' . $digest );
        if ( ! is_dir( $directory ) ) {
            if ( file_exists( $directory ) || is_link( $directory ) || ! @mkdir( $directory, 0700 ) ) {
                throw new RuntimeException( 'Unable to create private offsite resume storage.' );
            }
        }
        $real = realpath( $directory );
        if ( $real === false || $real !== $directory || is_link( $directory ) || ! is_writable( $directory ) ) {
            throw new RuntimeException( 'Offsite resume storage is unsafe.' );
        }
        @chmod( $directory, 0700 );
        return $directory;
    }

    private function write_resume_file( $directory, $path, $bytes ) {
        $temp = @tempnam( $directory, '.writing-' );
        if ( $temp === false || realpath( dirname( $temp ) ) !== realpath( $directory ) ) {
            if ( is_string( $temp ) ) { @unlink( $temp ); }
            throw new RuntimeException( 'Unable to allocate private offsite resume metadata.' );
        }
        @chmod( $temp, 0600 );
        if ( file_put_contents( $temp, $bytes, LOCK_EX ) !== strlen( $bytes ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Unable to save private offsite resume metadata.' );
        }
        @chmod( $path, 0600 );
    }

    private function compare_files( $first, $second ) {
        return $this->file_digest( $first ) === $this->file_digest( $second );
    }

    private function publish_without_overwrite( $staged, $destination ) {
        if ( file_exists( $destination ) || is_link( $destination ) ) {
            if ( is_link( $destination ) || ! is_file( $destination ) || ! $this->compare_files( $staged, $destination ) ) {
                throw new RuntimeException( 'A different local backup or manifest already exists.' );
            }
            return false;
        }
        if ( ! @link( $staged, $destination ) ) {
            throw new RuntimeException( 'Unable to publish the offsite download without overwriting a file.' );
        }
        @chmod( $destination, 0600 );
        return true;
    }

    public function download( $archive, $progress_callback = null ) {
        if ( $progress_callback !== null && ! is_callable( $progress_callback ) ) {
            throw new InvalidArgumentException( 'Invalid offsite download progress callback.' );
        }
        $archive = $this->archive_name( $archive );
        $destination = $this->local_root . DIRECTORY_SEPARATOR . $archive;
        $manifest_destination = Trasloco_Integrity::manifest_path( $destination );
        $staged_manifest = @tempnam( $this->local_root, '.tr-offsite-' );
        if ( $staged_manifest === false ) {
            if ( $staged_manifest !== false ) { @unlink( $staged_manifest ); }
            throw new RuntimeException( 'Unable to create private offsite staging files.' );
        }
        @chmod( $staged_manifest, 0600 );
        $resume_lock = false;
        $archive_stream = false;
        $staged_sidecar = null;
        try {
            $this->download_one( $archive . '.manifest.json', $staged_manifest, Trasloco_Integrity::MAX_MANIFEST_BYTES );
            $manifest_data = $this->manifest_archive_data( $staged_manifest, $archive );
            $expected_size = $manifest_data['bytes'];
            $expected_digest = hash_file( 'sha256', $staged_manifest );
            $manifest_size = filesize( $staged_manifest );
            if ( ! is_string( $expected_digest ) || $manifest_size === false ) {
                throw new RuntimeException( 'Unable to verify the remote integrity manifest.' );
            }
            $resume_dir = $this->resume_directory( $archive, $expected_digest );
            $part_path = $resume_dir . DIRECTORY_SEPARATOR . 'download.part';
            $resume_manifest = $resume_dir . DIRECTORY_SEPARATOR . 'manifest.json';
            $metadata_path = $resume_dir . DIRECTORY_SEPARATOR . 'transfer.json';
            $lock_path = $resume_dir . DIRECTORY_SEPARATOR . 'transfer.lock';
            $stale_sidecar_path = $part_path . '.manifest.json';
            foreach ( array( $part_path, $resume_manifest, $metadata_path, $lock_path, $stale_sidecar_path ) as $state_path ) {
                if ( is_link( $state_path ) || ( file_exists( $state_path ) && ! is_file( $state_path ) ) ) {
                    throw new RuntimeException( 'Offsite resume state contains an unsafe file.' );
                }
            }
            $resume_lock = @fopen( $lock_path, 'c+b' );
            if ( $resume_lock === false ) {
                throw new RuntimeException( 'Unable to lock private offsite resume state.' );
            }
            @chmod( $lock_path, 0600 );
            $lock_stat = fstat( $resume_lock );
            $lock_path_stat = @lstat( $lock_path );
            if ( ! is_array( $lock_stat ) || ! is_array( $lock_path_stat ) || $lock_stat['nlink'] !== 1 ||
                $lock_stat['dev'] !== $lock_path_stat['dev'] || $lock_stat['ino'] !== $lock_path_stat['ino'] ||
                ! flock( $resume_lock, LOCK_EX | LOCK_NB ) ) {
                throw new RuntimeException( 'Offsite download is already running or its state is unsafe.' );
            }

            // A process can stop after creating the temporary hard link used
            // for final verification. Remove only that exact copy after
            // proving it still matches the private source manifest.
            if ( file_exists( $stale_sidecar_path ) ) {
                if ( ! is_file( $resume_manifest ) || ! is_file( $stale_sidecar_path ) ||
                    ! $this->compare_files( $stale_sidecar_path, $resume_manifest ) || ! @unlink( $stale_sidecar_path ) ) {
                    throw new RuntimeException( 'Stale offsite manifest staging file is unsafe.' );
                }
            }

            $expected_state = array(
                'version' => 1,
                'archive' => $archive,
                'bytes' => $expected_size,
                'sha256' => $manifest_data['sha256'],
                'manifest_sha256' => $expected_digest,
            );
            $has_metadata = file_exists( $metadata_path );
            $has_partial = file_exists( $part_path );
            $has_manifest = file_exists( $resume_manifest );
            if ( $has_metadata ) {
                $metadata_size = filesize( $metadata_path );
                $metadata_stat = @lstat( $metadata_path );
                $resume_manifest_stat = @lstat( $resume_manifest );
                $published_manifest_stat = is_link( $manifest_destination ) ? false : @lstat( $manifest_destination );
                $resume_manifest_links = 1;
                if ( is_array( $resume_manifest_stat ) && is_array( $published_manifest_stat ) &&
                    $resume_manifest_stat['dev'] === $published_manifest_stat['dev'] &&
                    $resume_manifest_stat['ino'] === $published_manifest_stat['ino'] ) {
                    $resume_manifest_links = 2;
                }
                $state = $metadata_size !== false && $metadata_size <= 4096
                    ? json_decode( (string) file_get_contents( $metadata_path ), true ) : null;
                $comparable_state = $state;
                $comparable_expected = $expected_state;
                if ( is_array( $comparable_state ) ) { ksort( $comparable_state ); }
                ksort( $comparable_expected );
                if ( ! is_array( $metadata_stat ) || ! is_array( $resume_manifest_stat ) ||
                    $metadata_stat['nlink'] !== 1 || $resume_manifest_stat['nlink'] !== $resume_manifest_links ||
                    ! is_array( $state ) || $comparable_state !== $comparable_expected || ! $has_manifest ||
                    hash_file( 'sha256', $resume_manifest ) !== $expected_digest ) {
                    throw new RuntimeException( 'Private offsite resume metadata is invalid or does not match the remote archive.' );
                }
                if ( ! @chmod( $metadata_path, 0600 ) || ! @chmod( $resume_manifest, 0600 ) ) {
                    throw new RuntimeException( 'Unable to protect private offsite resume metadata.' );
                }
            } elseif ( $has_partial || $has_manifest ) {
                // A process can stop while creating initial metadata. These
                // private staging files are safe to discard before any bytes
                // become visible in the backup directory.
                if ( $has_partial ) { @unlink( $part_path ); }
                if ( $has_manifest ) { @unlink( $resume_manifest ); }
            }
            if ( ! file_exists( $metadata_path ) ) {
                $manifest_bytes = file_get_contents( $staged_manifest );
                if ( ! is_string( $manifest_bytes ) ) {
                    throw new RuntimeException( 'Unable to read the remote integrity manifest.' );
                }
                $this->write_resume_file( $resume_dir, $resume_manifest, $manifest_bytes );
                $state_bytes = json_encode( $expected_state, JSON_UNESCAPED_SLASHES );
                if ( ! is_string( $state_bytes ) ) {
                    throw new RuntimeException( 'Unable to encode offsite resume metadata.' );
                }
                $this->write_resume_file( $resume_dir, $metadata_path, $state_bytes );
            }

            $archive_stream = @fopen( $part_path, 'c+b' );
            if ( $archive_stream === false ) {
                throw new RuntimeException( 'Unable to open private offsite staging file.' );
            }
            @chmod( $part_path, 0600 );
            $part_stat = fstat( $archive_stream );
            $part_path_stat = @lstat( $part_path );
            $published_archive_stat = is_link( $destination ) ? false : @lstat( $destination );
            $part_links = 1;
            if ( is_array( $part_path_stat ) && is_array( $published_archive_stat ) &&
                $part_path_stat['dev'] === $published_archive_stat['dev'] &&
                $part_path_stat['ino'] === $published_archive_stat['ino'] ) {
                $part_links = 2;
            }
            if ( ! is_array( $part_stat ) || ! is_array( $part_path_stat ) || $part_stat['nlink'] !== $part_links ||
                $part_stat['dev'] !== $part_path_stat['dev'] || $part_stat['ino'] !== $part_path_stat['ino'] ) {
                throw new RuntimeException( 'Private offsite staging file is unsafe.' );
            }
            $offset = (int) $part_stat['size'];
            if ( $offset > $expected_size ) {
                throw new RuntimeException( 'Private offsite staging file exceeds the remote manifest size.' );
            }
            // A crash during a write leaves a trailing partial block. Repeat
            // only that block, then continue with verified HTTP byte ranges.
            $offset -= $offset % self::DOWNLOAD_CHUNK_BYTES;
            if ( ! ftruncate( $archive_stream, $offset ) || fseek( $archive_stream, $offset, SEEK_SET ) !== 0 ) {
                throw new RuntimeException( 'Unable to align private offsite staging for resume.' );
            }
            $disk_free = @disk_free_space( $this->local_root );
            if ( $disk_free === false || $expected_size - $offset > $disk_free - min( $disk_free, $manifest_size ) ) {
                throw new RuntimeException( 'Insufficient or unavailable local disk space for the verified archive.' );
            }
            while ( $offset < $expected_size ) {
                $length = min( self::DOWNLOAD_CHUNK_BYTES, $expected_size - $offset );
                $range = $this->retry_transient( function () use ( $archive, $offset, $length, $expected_size ) {
                    return $this->remote->open_read_range( $archive, $offset, $length, $expected_size );
                } );
                try {
                    $bytes = stream_get_contents( $range, $length + 1 );
                    if ( ! is_string( $bytes ) || strlen( $bytes ) !== $length ) {
                        throw new RuntimeException( 'Offsite storage returned an incomplete byte range.' );
                    }
                } finally {
                    fclose( $range );
                }
                $written = 0;
                while ( $written < $length ) {
                    $count = @fwrite( $archive_stream, substr( $bytes, $written ) );
                    if ( $count === false || $count === 0 ) {
                        throw new RuntimeException( 'Unable to write the offsite staging file.' );
                    }
                    $written += $count;
                }
                if ( ! fflush( $archive_stream ) || ( function_exists( 'fsync' ) && ! fsync( $archive_stream ) ) ) {
                    throw new RuntimeException( 'Unable to persist the offsite download checkpoint.' );
                }
                $offset += $length;
                if ( $progress_callback !== null && call_user_func( $progress_callback, $offset, $expected_size ) === false ) {
                    throw new RuntimeException( 'Offsite download was cancelled or lost its job lease.' );
                }
            }
            fclose( $archive_stream );
            $archive_stream = false;
            $staged_sidecar = $part_path . '.manifest.json';
            if ( is_link( $staged_sidecar ) ) {
                throw new RuntimeException( 'Unable to stage the remote integrity manifest.' );
            }
            if ( file_exists( $staged_sidecar ) ) {
                if ( ! is_file( $staged_sidecar ) || ! $this->compare_files( $staged_sidecar, $resume_manifest ) ||
                    ! @unlink( $staged_sidecar ) ) {
                    throw new RuntimeException( 'Stale offsite manifest staging file is unsafe.' );
                }
            }
            if ( ! @link( $resume_manifest, $staged_sidecar ) ) {
                throw new RuntimeException( 'Unable to stage the remote integrity manifest.' );
            }
            $staged_archive = $part_path;
            if ( Trasloco_Integrity::verify( $staged_archive )['status'] !== 'verified' ) {
                @unlink( $part_path );
                @unlink( $resume_manifest );
                @unlink( $metadata_path );
                throw new RuntimeException( 'Downloaded backup failed integrity verification.' );
            }
            if ( file_exists( $destination ) || is_link( $destination ) ) {
                if ( is_link( $destination ) || ! is_file( $destination ) || ! $this->compare_files( $staged_archive, $destination ) ) {
                    throw new RuntimeException( 'A different local backup already exists.' );
                }
            }
            if ( file_exists( $manifest_destination ) || is_link( $manifest_destination ) ) {
                if ( is_link( $manifest_destination ) || ! is_file( $manifest_destination ) || ! $this->compare_files( $staged_sidecar, $manifest_destination ) ) {
                    throw new RuntimeException( 'A different local integrity manifest already exists.' );
                }
            }
            $created_archive = $this->publish_without_overwrite( $staged_archive, $destination );
            try {
                $created_manifest = $this->publish_without_overwrite( $staged_sidecar, $manifest_destination );
            } catch ( Throwable $error ) {
                if ( $created_archive ) {
                    @unlink( $destination );
                }
                throw $error;
            }
            @unlink( $part_path );
            @unlink( $resume_manifest );
            @unlink( $metadata_path );
            return array(
                'status' => $created_archive || $created_manifest ? 'downloaded' : 'already_present',
                'archive' => $archive,
                'bytes' => $this->file_digest( $destination )[0],
                'encrypted' => substr( $archive, -4 ) === '.enc',
            );
        } finally {
            if ( is_resource( $archive_stream ) ) { fclose( $archive_stream ); }
            @unlink( $staged_manifest );
            if ( isset( $staged_sidecar ) ) {
                @unlink( $staged_sidecar );
            }
            if ( is_resource( $resume_lock ) ) {
                flock( $resume_lock, LOCK_UN );
                fclose( $resume_lock );
            }
        }
    }
}
