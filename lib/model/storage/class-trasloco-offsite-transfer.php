<?php
/**
 * Verified, non-overwriting transfer between local backup files and a
 * configured storage adapter. Plaintext upload always needs explicit consent.
 */
class Trasloco_Offsite_Transfer {
    const MAX_INVENTORY_ITEMS = 10000;

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

    private function manifest_archive_size( $manifest, $archive ) {
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
            return $data['size_bytes'];
        }
        if ( isset( $data['schema'], $data['format'], $data['archive'], $data['bytes'], $data['sha256'] ) &&
            $data['schema'] === 1 && $data['format'] === 'wpress' && $data['archive'] === $archive &&
            is_int( $data['bytes'] ) && $data['bytes'] >= 0 && is_string( $data['sha256'] ) &&
            preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            return $data['bytes'];
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

    private function upload_one_attempt( $key, $path ) {
        $expected = $this->file_digest( $path );
        if ( $this->remote_matches( $key, $expected ) ) {
            return false;
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

    private function upload_one( $key, $path ) {
        return $this->retry_transient( function () use ( $key, $path ) {
            return $this->upload_one_attempt( $key, $path );
        } );
    }

    public function upload( $archive, $allow_unencrypted = false ) {
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
        $archive_uploaded = $this->upload_one( $archive, $path );
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

    public function download( $archive ) {
        $archive = $this->archive_name( $archive );
        $destination = $this->local_root . DIRECTORY_SEPARATOR . $archive;
        $manifest_destination = Trasloco_Integrity::manifest_path( $destination );
        $staged_archive = @tempnam( $this->local_root, '.tr-offsite-' );
        $staged_manifest = @tempnam( $this->local_root, '.tr-offsite-' );
        if ( $staged_archive === false || $staged_manifest === false ) {
            if ( $staged_archive !== false ) { @unlink( $staged_archive ); }
            if ( $staged_manifest !== false ) { @unlink( $staged_manifest ); }
            throw new RuntimeException( 'Unable to create private offsite staging files.' );
        }
        @chmod( $staged_archive, 0600 );
        @chmod( $staged_manifest, 0600 );
        $created_archive = false;
        try {
            $this->download_one( $archive . '.manifest.json', $staged_manifest, Trasloco_Integrity::MAX_MANIFEST_BYTES );
            $expected_size = $this->manifest_archive_size( $staged_manifest, $archive );
            $manifest_size = filesize( $staged_manifest );
            $disk_free = @disk_free_space( $this->local_root );
            if ( $disk_free === false || $expected_size > $disk_free - min( $disk_free, $manifest_size ) ) {
                throw new RuntimeException( 'Insufficient or unavailable local disk space for the verified archive.' );
            }
            $this->download_one( $archive, $staged_archive, $expected_size );
            if ( ! @rename( $staged_manifest, Trasloco_Integrity::manifest_path( $staged_archive ) ) ) {
                throw new RuntimeException( 'Unable to stage the remote integrity manifest.' );
            }
            $staged_sidecar = Trasloco_Integrity::manifest_path( $staged_archive );
            if ( Trasloco_Integrity::verify( $staged_archive )['status'] !== 'verified' ) {
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
            return array(
                'status' => $created_archive || $created_manifest ? 'downloaded' : 'already_present',
                'archive' => $archive,
                'bytes' => $this->file_digest( $destination )[0],
                'encrypted' => substr( $archive, -4 ) === '.enc',
            );
        } finally {
            @unlink( $staged_archive );
            @unlink( $staged_manifest );
            if ( isset( $staged_sidecar ) ) {
                @unlink( $staged_sidecar );
            }
        }
    }
}
