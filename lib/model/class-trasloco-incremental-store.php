<?php
/**
 * Private content-addressed storage for independently reconstructible archives.
 * A catalog contains every chunk of one logical archive; it never depends on
 * another catalog, so pruning a snapshot cannot break a retained snapshot.
 */
class Trasloco_Incremental_Store {
    const SCHEMA = 1;
    const CHUNK_BYTES = 4194304;
    const MAX_OBJECTS = 400000;
    const MAX_CATALOG_BYTES = 33554432;

    private $root;

    public function __construct( $root ) {
        if ( ! is_string( $root ) || $root === '' ) {
            throw new InvalidArgumentException( 'A private incremental storage root is required.' );
        }
        $this->root = rtrim( $root, DIRECTORY_SEPARATOR );
        if ( $this->root === '' ) { $this->root = DIRECTORY_SEPARATOR; }
    }

    /** Publish a catalog only after all immutable objects have been verified. */
    public function capture( $archive, $expected_sha256 = null ) {
        if ( $expected_sha256 !== null && ( ! is_string( $expected_sha256 ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) ) ) {
            throw new InvalidArgumentException( 'Expected archive checksum is invalid.' );
        }
        $this->ensure_root();
        return $this->locked( function () use ( $archive, $expected_sha256 ) {
            $source = $this->source( $archive );
            $catalog_path = $this->catalog_path( $source['name'] );
            if ( file_exists( $catalog_path ) || is_link( $catalog_path ) ) {
                throw new RuntimeException( 'Incremental snapshot already exists.' );
            }
            $input = $this->open_private_file( $source['path'] );
            $opened = fstat( $input );
            if ( ! self::same_file( $source['stat'], $opened ) ) {
                fclose( $input ); throw new RuntimeException( 'Source archive changed before incremental capture.' );
            }
            $chunks = array();
            $whole = hash_init( 'sha256' );
            $remaining = $source['stat']['size'];
            try {
                while ( $remaining > 0 ) {
                    if ( count( $chunks ) >= self::MAX_OBJECTS ) {
                        throw new RuntimeException( 'Archive exceeds the incremental catalog object limit.' );
                    }
                    $length = min( self::CHUNK_BYTES, $remaining );
                    $bytes = self::read_exact( $input, $length );
                    hash_update( $whole, $bytes );
                    $digest = hash( 'sha256', $bytes );
                    $this->store_object( $digest, $bytes );
                    $chunks[] = array( 'sha256' => $digest, 'size_bytes' => $length );
                    $remaining -= $length;
                }
                if ( self::read_exact( $input, 1, true ) !== '' ) {
                    throw new RuntimeException( 'Source archive grew during incremental capture.' );
                }
            } finally {
                fclose( $input );
            }
            clearstatcache( true, $source['path'] );
            if ( ! self::same_file( $source['stat'], @lstat( $source['path'] ) ) ) {
                throw new RuntimeException( 'Source archive changed during incremental capture.' );
            }
            $catalog = array(
                'schema' => self::SCHEMA,
                'archive' => $source['name'],
                'size_bytes' => $source['stat']['size'],
                'sha256' => hash_final( $whole ),
                'chunk_bytes' => self::CHUNK_BYTES,
                'chunks' => $chunks,
            );
            if ( $expected_sha256 !== null && ! hash_equals( $expected_sha256, $catalog['sha256'] ) ) {
                throw new RuntimeException( 'Archive bytes do not match the expected integrity manifest.' );
            }
            $this->publish_json( $catalog_path, $catalog );
            return array(
                'archive' => $source['name'],
                'size_bytes' => $catalog['size_bytes'],
                'sha256' => $catalog['sha256'],
                'objects' => count( $chunks ),
                'unique_objects' => $this->unique_object_count( $chunks ),
            );
        } );
    }

    /**
     * Capture a verified chunked-manifest archive in bounded, resumable batches.
     * The private journal is authoritative across worker crashes; its only
     * published catalog is still created atomically after every chunk matches
     * the already-verified archive manifest.
     */
    public function capture_batch( $archive, $expected_sha256, $manifest_sha256, $expected_chunks, $job_id, $max_chunks = 8 ) {
        if ( ! is_string( $expected_sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) ||
            ! is_string( $manifest_sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/D', $manifest_sha256 ) ||
            ! is_array( $expected_chunks ) || ! is_int( $job_id ) || $job_id < 1 ||
            ! is_int( $max_chunks ) || $max_chunks < 1 || $max_chunks > 32 ) {
            throw new InvalidArgumentException( 'Invalid resumable incremental capture request.' );
        }
        $this->ensure_root();
        return $this->locked( function () use ( $archive, $expected_sha256, $manifest_sha256, $expected_chunks, $job_id, $max_chunks ) {
            $source = $this->source( $archive );
            $expected_count = intdiv( $source['stat']['size'], self::CHUNK_BYTES ) +
                ( $source['stat']['size'] % self::CHUNK_BYTES === 0 ? 0 : 1 );
            if ( count( $expected_chunks ) !== $expected_count || $expected_count > self::MAX_OBJECTS ) {
                throw new RuntimeException( 'Verified archive manifest does not match the source size.' );
            }
            foreach ( $expected_chunks as $digest ) {
                if ( ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
                    throw new RuntimeException( 'Verified archive manifest contains an invalid chunk digest.' );
                }
            }
            $catalog_path = $this->catalog_path( $source['name'] );
            $journal_path = $this->capture_journal_path( $job_id );
            if ( file_exists( $catalog_path ) || is_link( $catalog_path ) ) {
                $catalog = $this->read_catalog( $source['name'] );
                if ( $catalog['size_bytes'] !== $source['stat']['size'] ||
                    ! hash_equals( $expected_sha256, $catalog['sha256'] ) ) {
                    throw new RuntimeException( 'Published incremental snapshot conflicts with this backup job.' );
                }
                $this->remove_capture_journal( $journal_path );
                return array( 'status' => 'completed', 'archive' => $source['name'], 'size_bytes' => $catalog['size_bytes'],
                    'sha256' => $catalog['sha256'], 'objects' => count( $catalog['chunks'] ) );
            }

            $header = array( 'schema' => self::SCHEMA, 'job_id' => $job_id, 'archive' => $source['name'],
                'size_bytes' => $source['stat']['size'], 'mtime' => $source['stat']['mtime'],
                'ctime' => $source['stat']['ctime'], 'ino' => $source['stat']['ino'], 'dev' => $source['stat']['dev'],
                'sha256' => $expected_sha256, 'manifest_sha256' => $manifest_sha256 );
            $handle = $this->open_capture_journal( $journal_path, $header );
            $chunks = array();
            $valid_bytes = 0;
            try {
                $line = fgets( $handle );
                $decoded_header = is_string( $line ) && substr( $line, -1 ) === "\n"
                    ? json_decode( rtrim( $line, "\r\n" ), true ) : null;
                if ( $decoded_header !== $header ) {
                    throw new RuntimeException( 'Incremental capture journal does not match its source or manifest.' );
                }
                $valid_bytes = ftell( $handle );
                while ( ( $line = fgets( $handle ) ) !== false ) {
                    if ( substr( $line, -1 ) !== "\n" ) { break; }
                    $entry = json_decode( rtrim( $line, "\r\n" ), true );
                    $index = count( $chunks );
                    $expected_size = min( self::CHUNK_BYTES, $source['stat']['size'] - $index * self::CHUNK_BYTES );
                    if ( ! is_array( $entry ) || array_keys( $entry ) !== array( 'sha256', 'size_bytes' ) ||
                        ! isset( $expected_chunks[ $index ] ) || $entry['sha256'] !== $expected_chunks[ $index ] ||
                        ! is_int( $entry['size_bytes'] ) || $entry['size_bytes'] !== $expected_size ) {
                        throw new RuntimeException( 'Incremental capture journal contains an invalid chunk record.' );
                    }
                    $chunks[] = $entry;
                    $valid_bytes = ftell( $handle );
                }
                if ( ! @ftruncate( $handle, $valid_bytes ) || fseek( $handle, 0, SEEK_END ) !== 0 ) {
                    throw new RuntimeException( 'Cannot recover the incremental capture journal position.' );
                }
                $offset = count( $chunks ) * self::CHUNK_BYTES;
                if ( $offset > $source['stat']['size'] ) { throw new RuntimeException( 'Incremental capture journal exceeds the source size.' ); }
                $input = $this->open_private_file( $source['path'] );
                try {
                    if ( fseek( $input, $offset, SEEK_SET ) !== 0 ) { throw new RuntimeException( 'Cannot resume archive reading.' ); }
                    $added = 0;
                    while ( $offset < $source['stat']['size'] && $added < $max_chunks ) {
                        $index = count( $chunks );
                        $length = min( self::CHUNK_BYTES, $source['stat']['size'] - $offset );
                        $bytes = self::read_exact( $input, $length );
                        $digest = hash( 'sha256', $bytes );
                        if ( ! hash_equals( $expected_chunks[ $index ], $digest ) ) {
                            throw new RuntimeException( 'Archive chunk changed after integrity verification.' );
                        }
                        $this->store_object( $digest, $bytes );
                        $entry = array( 'sha256' => $digest, 'size_bytes' => $length );
                        self::write( $handle, json_encode( $entry, JSON_UNESCAPED_SLASHES ) . "\n" );
                        $chunks[] = $entry;
                        $offset += $length;
                        $added++;
                    }
                    if ( $offset >= $source['stat']['size'] && self::read_exact( $input, 1, true ) !== '' ) {
                        throw new RuntimeException( 'Source archive grew during incremental capture.' );
                    }
                } finally { fclose( $input ); }
            } catch ( Throwable $error ) {
                fclose( $handle );
                throw $error;
            }
            if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) ) {
                fclose( $handle ); throw new RuntimeException( 'Cannot synchronize the incremental capture journal.' );
            }
            fclose( $handle );
            clearstatcache( true, $source['path'] );
            if ( ! self::same_file( $source['stat'], @lstat( $source['path'] ) ) ) {
                throw new RuntimeException( 'Source archive changed during incremental capture.' );
            }
            $offset = min( count( $chunks ) * self::CHUNK_BYTES, $source['stat']['size'] );
            if ( $offset < $source['stat']['size'] ) {
                return array( 'status' => 'pending', 'archive' => $source['name'], 'bytes' => $offset,
                    'size_bytes' => $source['stat']['size'], 'chunks' => count( $chunks ) );
            }
            if ( count( $chunks ) !== $expected_count ) { throw new RuntimeException( 'Incremental capture ended before all chunks were journaled.' ); }
            $catalog = array( 'schema' => self::SCHEMA, 'archive' => $source['name'],
                'size_bytes' => $source['stat']['size'], 'sha256' => $expected_sha256,
                'chunk_bytes' => self::CHUNK_BYTES, 'chunks' => $chunks );
            $this->publish_json( $catalog_path, $catalog );
            $this->remove_capture_journal( $journal_path );
            return array( 'status' => 'completed', 'archive' => $source['name'], 'size_bytes' => $catalog['size_bytes'],
                'sha256' => $catalog['sha256'], 'objects' => count( $chunks ) );
        } );
    }

    /** Remove an unpublished capture journal after a job is cancelled or fails. */
    public function discard_capture( $job_id ) {
        if ( ! is_int( $job_id ) || $job_id < 1 ) { throw new InvalidArgumentException( 'Invalid incremental capture job id.' ); }
        $this->ensure_root();
        return $this->locked( function () use ( $job_id ) {
            $this->remove_capture_journal( $this->capture_journal_path( $job_id ) );
            return true;
        } );
    }

    /** Verify every referenced object and the reconstructed whole-archive digest. */
    public function verify( $archive ) {
        $this->ensure_root();
        return $this->locked( function () use ( $archive ) {
            $catalog = $this->read_catalog( $archive );
            $this->stream_catalog( $catalog, null );
            return array(
                'archive' => $catalog['archive'],
                'size_bytes' => $catalog['size_bytes'],
                'sha256' => $catalog['sha256'],
                'objects' => count( $catalog['chunks'] ),
            );
        } );
    }

    /** Return validated catalog metadata without reading all object contents. */
    public function list_snapshots() {
        $this->ensure_root();
        return $this->locked( function () {
            $snapshots = array();
            foreach ( new DirectoryIterator( $this->catalog_dir() ) as $entry ) {
                if ( $entry->isDot() ) { continue; }
                if ( $entry->isLink() || ! $entry->isFile() || substr( $entry->getFilename(), -5 ) !== '.json' ) {
                    throw new RuntimeException( 'Incremental catalog directory contains an unsafe entry.' );
                }
                $name = substr( $entry->getFilename(), 0, -5 );
                $catalog = $this->read_catalog( $name );
                $snapshots[] = array( 'archive' => $name, 'size_bytes' => $catalog['size_bytes'],
                    'sha256' => $catalog['sha256'], 'objects' => count( $catalog['chunks'] ),
                    'unique_objects' => $this->unique_object_count( $catalog['chunks'] ),
                    'mtime' => (int) $entry->getMTime() );
            }
            usort( $snapshots, function ( $a, $b ) { return strcmp( $a['archive'], $b['archive'] ); } );
            return $snapshots;
        } );
    }

    /** Measure bytes currently retained by the snapshot store and source archives. */
    public function storage_stats() {
        $this->ensure_root();
        return $this->locked( function () {
            $logical = 0;
            $catalog_bytes = 0;
            $source_bytes = 0;
            $manifest_bytes = 0;
            $snapshots = array();
            foreach ( new DirectoryIterator( $this->catalog_dir() ) as $entry ) {
                if ( $entry->isDot() ) { continue; }
                if ( $entry->isLink() || ! $entry->isFile() ) { throw new RuntimeException( 'Incremental catalog directory contains an unsafe entry.' ); }
                if ( substr( $entry->getFilename(), -5 ) === '.json' ) {
                    $snapshots[] = $this->read_catalog( substr( $entry->getFilename(), 0, -5 ) );
                }
            }
            foreach ( new DirectoryIterator( $this->catalog_dir() ) as $entry ) {
                if ( $entry->isDot() ) { continue; }
                if ( $entry->isLink() || ! $entry->isFile() ) { throw new RuntimeException( 'Incremental catalog directory contains an unsafe entry.' ); }
                $catalog_bytes += (int) $entry->getSize();
            }
            foreach ( $snapshots as $snapshot ) {
                $logical += (int) $snapshot['size_bytes'];
                $source = dirname( $this->root ) . DIRECTORY_SEPARATOR . $snapshot['archive'];
                if ( is_link( $source ) || ( file_exists( $source ) && ! is_file( $source ) ) ) { throw new RuntimeException( 'Snapshot source archive path is unsafe.' ); }
                if ( is_file( $source ) ) { $source_bytes += (int) filesize( $source ); }
                $manifest = $source . '.manifest.json';
                if ( is_link( $manifest ) || ( file_exists( $manifest ) && ! is_file( $manifest ) ) ) { throw new RuntimeException( 'Snapshot integrity manifest path is unsafe.' ); }
                if ( is_file( $manifest ) ) { $manifest_bytes += (int) filesize( $manifest ); }
            }
            $object_bytes = 0;
            foreach ( new DirectoryIterator( $this->object_dir() ) as $shard ) {
                if ( $shard->isDot() ) { continue; }
                if ( $shard->isLink() || ! $shard->isDir() ) { throw new RuntimeException( 'Incremental object directory contains an unsafe entry.' ); }
                foreach ( new DirectoryIterator( $shard->getPathname() ) as $object ) {
                    if ( $object->isDot() ) { continue; }
                    if ( $object->isLink() || ! $object->isFile() ) { throw new RuntimeException( 'Incremental object shard contains an unsafe entry.' ); }
                    $object_bytes += (int) $object->getSize();
                }
            }
            $pending_bytes = 0;
            foreach ( new DirectoryIterator( $this->capture_dir() ) as $entry ) {
                if ( ! $entry->isDot() ) {
                    if ( $entry->isLink() || ! $entry->isFile() ) { throw new RuntimeException( 'Incremental pending directory contains an unsafe entry.' ); }
                    $pending_bytes += (int) $entry->getSize();
                }
            }
            $store_bytes = $object_bytes + $catalog_bytes + $pending_bytes;
            return array( 'snapshots' => count( $snapshots ), 'logical_bytes' => $logical,
                'object_bytes' => $object_bytes, 'catalog_bytes' => $catalog_bytes,
                'pending_bytes' => $pending_bytes, 'source_archive_bytes' => $source_bytes,
                'source_manifest_bytes' => $manifest_bytes,
                'physical_bytes' => $store_bytes + $source_bytes + $manifest_bytes,
                'net_saved_bytes' => $logical - $store_bytes - $source_bytes - $manifest_bytes );
        } );
    }

    /** Remove a full archive only after its catalog reconstructs the exact bytes. */
    public function compact( $archive ) {
        $name = $this->archive_name( $archive );
        if ( strpos( $name, 'recovery-' ) === 0 ) { throw new InvalidArgumentException( 'Recovery-point archives cannot be compacted.' ); }
        $this->ensure_root();
        return $this->locked( function () use ( $name ) {
            $source = dirname( $this->root ) . DIRECTORY_SEPARATOR . $name;
            if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source ) ) {
                throw new RuntimeException( 'Source archive is missing or unsafe.' );
            }
            $catalog = $this->read_catalog( $name );
            $this->stream_catalog( $catalog, null );
            $source_digest = hash_file( 'sha256', $source );
            if ( $source_digest === false || ! hash_equals( $catalog['sha256'], $source_digest ) ||
                (int) filesize( $source ) !== (int) $catalog['size_bytes'] ) {
                throw new RuntimeException( 'Source archive does not match its verified snapshot.' );
            }
            $manifest = $source . '.manifest.json';
            if ( is_link( $manifest ) || ( file_exists( $manifest ) && ! is_file( $manifest ) ) ) {
                throw new RuntimeException( 'Archive integrity manifest is unsafe.' );
            }
            $manifest_bytes = is_file( $manifest ) ? (int) filesize( $manifest ) : 0;
            $tombstone = dirname( $source ) . DIRECTORY_SEPARATOR . '.trasloco-compacting-' . bin2hex( random_bytes( 8 ) ) . '.wpress';
            if ( file_exists( $tombstone ) || is_link( $tombstone ) || ! @rename( $source, $tombstone ) ) {
                throw new RuntimeException( 'Cannot isolate source archive for safe compaction.' );
            }
            // Check the moved inode too, so a path swap between the initial
            // digest check and rename cannot cause a different file to vanish.
            $moved_digest = hash_file( 'sha256', $tombstone );
            if ( $moved_digest === false || ! hash_equals( $catalog['sha256'], $moved_digest ) ||
                (int) filesize( $tombstone ) !== (int) $catalog['size_bytes'] ) {
                if ( ! file_exists( $source ) && ! is_link( $source ) ) { @rename( $tombstone, $source ); }
                throw new RuntimeException( 'Source archive changed during compaction; it was retained.' );
            }
            if ( ! @unlink( $tombstone ) ) {
                if ( ! file_exists( $source ) && ! is_link( $source ) ) { @rename( $tombstone, $source ); }
                throw new RuntimeException( 'Cannot remove verified source archive.' );
            }
            if ( is_file( $manifest ) && ! @unlink( $manifest ) ) {
                throw new RuntimeException( 'Archive was compacted, but its stale integrity manifest could not be removed.' );
            }
            return array( 'archive' => $name, 'size_bytes' => $catalog['size_bytes'],
                'manifest_bytes' => $manifest_bytes, 'sha256' => $catalog['sha256'],
                'removed_bytes' => (int) $catalog['size_bytes'] + $manifest_bytes );
        } );
    }

    /** Reconstruct byte-for-byte into a new destination without replacing files. */
    public function materialize( $archive, $destination ) {
        $this->ensure_root();
        if ( ! is_string( $destination ) || $destination === '' || substr( $destination, -7 ) !== '.wpress' ) {
            throw new InvalidArgumentException( 'A new .wpress destination is required.' );
        }
        return $this->locked( function () use ( $archive, $destination ) {
            if ( file_exists( $destination ) || is_link( $destination ) ) {
                throw new RuntimeException( 'Materialized archive destination already exists.' );
            }
            $catalog = $this->read_catalog( $archive );
            $directory = dirname( $destination );
            if ( ! is_dir( $directory ) || is_link( $directory ) || ! is_writable( $directory ) ) {
                throw new RuntimeException( 'Materialized archive directory is unavailable.' );
            }
            $temp = @tempnam( $directory, '.trasloco-materialize-' );
            if ( $temp === false ) { throw new RuntimeException( 'Cannot allocate private materialization file.' ); }
            @chmod( $temp, 0600 );
            $output = @fopen( $temp, 'wb' );
            if ( ! $output ) { @unlink( $temp ); throw new RuntimeException( 'Cannot write materialized archive.' ); }
            try {
                $this->stream_catalog( $catalog, $output );
                if ( ! fflush( $output ) || ( function_exists( 'fsync' ) && ! @fsync( $output ) ) ) {
                    throw new RuntimeException( 'Cannot synchronize materialized archive.' );
                }
            } catch ( Throwable $error ) {
                fclose( $output ); @unlink( $temp ); throw $error;
            }
            fclose( $output );
            if ( ! @link( $temp, $destination ) ) {
                @unlink( $temp );
                throw new RuntimeException( 'Cannot publish materialized archive without replacing a file.' );
            }
            @unlink( $temp );
            return array( 'archive' => $catalog['archive'], 'destination' => $destination,
                'size_bytes' => $catalog['size_bytes'], 'sha256' => $catalog['sha256'] );
        } );
    }

    /**
     * Keep the named snapshots and remove unreferenced catalogs and objects.
     * All catalogs are validated before the first deletion; malformed metadata
     * therefore stops pruning without changing the store.
     */
    public function prune( $keep ) {
        $this->ensure_root();
        if ( ! is_array( $keep ) ) { throw new InvalidArgumentException( 'A snapshot retention list is required.' ); }
        $keep_set = array();
        foreach ( $keep as $name ) {
            $keep_set[ $this->archive_name( $name ) ] = true;
        }
        if ( empty( $keep_set ) ) { throw new InvalidArgumentException( 'Retention must keep at least one snapshot.' ); }
        return $this->locked( function () use ( $keep_set ) {
            $catalog_dir = $this->catalog_dir();
            $catalogs = array();
            $live_objects = array();
            foreach ( new DirectoryIterator( $catalog_dir ) as $entry ) {
                if ( $entry->isDot() ) { continue; }
                if ( strpos( $entry->getFilename(), '.trasloco-catalog-' ) === 0 &&
                    $entry->isFile() && ! $entry->isLink() ) {
                    // A process may have died before linking this unpublished
                    // temporary catalog to its visible snapshot name.
                    if ( ! @unlink( $entry->getPathname() ) ) { throw new RuntimeException( 'Cannot remove an abandoned catalog temporary.' ); }
                    continue;
                }
                if ( $entry->isLink() || ! $entry->isFile() || substr( $entry->getFilename(), -5 ) !== '.json' ) {
                    throw new RuntimeException( 'Incremental catalog directory contains an unsafe entry.' );
                }
                $name = substr( $entry->getFilename(), 0, -5 );
                $catalog = $this->read_catalog( $name );
                $catalogs[ $name ] = $catalog;
                if ( isset( $keep_set[ $name ] ) ) {
                    foreach ( $catalog['chunks'] as $chunk ) { $live_objects[ $chunk['sha256'] ] = true; }
                }
            }
            foreach ( array_keys( $keep_set ) as $name ) {
                if ( ! isset( $catalogs[ $name ] ) ) { throw new RuntimeException( 'A retained incremental snapshot is missing.' ); }
            }
            $removed_catalogs = array();
            foreach ( array_keys( $catalogs ) as $name ) {
                if ( isset( $keep_set[ $name ] ) ) { continue; }
                $path = $this->catalog_path( $name );
                if ( ! @unlink( $path ) ) { throw new RuntimeException( 'Cannot remove a pruned snapshot catalog.' ); }
                $removed_catalogs[] = $name;
            }
            $removed_objects = $this->remove_unreferenced_objects( $live_objects );
            return array( 'removed_snapshots' => $removed_catalogs, 'removed_objects' => $removed_objects,
                'retained_snapshots' => array_keys( $keep_set ) );
        } );
    }

    private function source( $archive ) {
        $name = is_string( $archive ) ? basename( $archive ) : '';
        if ( ! is_string( $archive ) || $archive === '' || strlen( $name ) > 220 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) ||
            strpos( $name, '..' ) !== false ) {
            throw new InvalidArgumentException( 'Invalid incremental archive name.' );
        }
        clearstatcache( true, $archive );
        $stat = @lstat( $archive );
        if ( ! is_array( $stat ) || is_link( $archive ) || ! is_file( $archive ) || ! is_readable( $archive ) ) {
            throw new RuntimeException( 'Incremental archive is missing, unsafe or unreadable.' );
        }
        return array( 'name' => $name, 'path' => $archive,
            'stat' => array( 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'],
                'ctime' => (int) $stat['ctime'], 'ino' => (int) $stat['ino'], 'dev' => (int) $stat['dev'] ) );
    }

    private function archive_name( $archive ) {
        if ( ! is_string( $archive ) || strlen( $archive ) > 220 || basename( $archive ) !== $archive ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $archive ) || strpos( $archive, '..' ) !== false ) {
            throw new InvalidArgumentException( 'Invalid incremental snapshot name.' );
        }
        return $archive;
    }

    private function read_catalog( $archive ) {
        $archive = $this->archive_name( $archive );
        $path = $this->catalog_path( $archive );
        clearstatcache( true, $path );
        $stat = @lstat( $path );
        if ( ! is_array( $stat ) || is_link( $path ) || ! is_file( $path ) ||
            (int) $stat['size'] > self::MAX_CATALOG_BYTES || ( isset( $stat['nlink'] ) && $stat['nlink'] !== 1 ) ) {
            throw new RuntimeException( 'Incremental snapshot catalog is missing or unsafe.' );
        }
        $handle = $this->open_private_file( $path );
        $json = @stream_get_contents( $handle, self::MAX_CATALOG_BYTES + 1 );
        fclose( $handle );
        clearstatcache( true, $path );
        $after = @lstat( $path );
        if ( ! self::same_file( $stat, $after ) || is_link( $path ) || ! is_string( $json ) || strlen( $json ) > self::MAX_CATALOG_BYTES ) {
            throw new RuntimeException( 'Incremental snapshot catalog changed while being read.' );
        }
        $catalog = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $catalog ) || array_keys( $catalog ) !== array( 'schema','archive','size_bytes','sha256','chunk_bytes','chunks' ) ||
            $catalog['schema'] !== self::SCHEMA || $catalog['archive'] !== $archive ||
            ! is_int( $catalog['size_bytes'] ) || $catalog['size_bytes'] < 0 ||
            ! is_string( $catalog['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $catalog['sha256'] ) ||
            $catalog['chunk_bytes'] !== self::CHUNK_BYTES || ! is_array( $catalog['chunks'] ) ||
            count( $catalog['chunks'] ) > self::MAX_OBJECTS ) {
            throw new RuntimeException( 'Incremental snapshot catalog is malformed.' );
        }
        $expected = intdiv( $catalog['size_bytes'], self::CHUNK_BYTES ) +
            ( $catalog['size_bytes'] % self::CHUNK_BYTES === 0 ? 0 : 1 );
        if ( count( $catalog['chunks'] ) !== $expected ) { throw new RuntimeException( 'Incremental snapshot chunk count is invalid.' ); }
        $total = 0;
        foreach ( $catalog['chunks'] as $index => $chunk ) {
            $size = min( self::CHUNK_BYTES, $catalog['size_bytes'] - $total );
            if ( ! is_int( $index ) || $index < 0 || ! is_array( $chunk ) ||
                array_keys( $chunk ) !== array( 'sha256','size_bytes' ) ||
                ! is_string( $chunk['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $chunk['sha256'] ) ||
                ! is_int( $chunk['size_bytes'] ) || $chunk['size_bytes'] !== $size ) {
                throw new RuntimeException( 'Incremental snapshot chunk entry is invalid.' );
            }
            $total += $chunk['size_bytes'];
        }
        if ( $total !== $catalog['size_bytes'] ) { throw new RuntimeException( 'Incremental snapshot size is invalid.' ); }
        return $catalog;
    }

    private function stream_catalog( $catalog, $output ) {
        $whole = hash_init( 'sha256' );
        $total = 0;
        foreach ( $catalog['chunks'] as $chunk ) {
            $path = $this->object_path( $chunk['sha256'] );
            if ( is_link( dirname( $path ) ) || ! is_dir( dirname( $path ) ) ) {
                throw new RuntimeException( 'Incremental snapshot object is missing or unsafe.' );
            }
            $input = $this->open_private_file( $path );
            $stat = fstat( $input );
            if ( ! is_array( $stat ) || (int) $stat['size'] !== $chunk['size_bytes'] ) {
                fclose( $input ); throw new RuntimeException( 'Incremental snapshot object size is invalid.' );
            }
            $object_hash = hash_init( 'sha256' );
            $object_bytes = 0;
            try {
                while ( ! feof( $input ) ) {
                    $bytes = fread( $input, 1024 * 1024 );
                    if ( $bytes === false || ( $bytes === '' && ! feof( $input ) ) ) {
                        throw new RuntimeException( 'Cannot read an incremental snapshot object.' );
                    }
                    if ( $bytes === '' ) { break; }
                    hash_update( $object_hash, $bytes );
                    hash_update( $whole, $bytes );
                    $object_bytes += strlen( $bytes );
                    if ( $output !== null ) { self::write( $output, $bytes ); }
                }
            } finally { fclose( $input ); }
            if ( $object_bytes !== $chunk['size_bytes'] || ! hash_equals( $chunk['sha256'], hash_final( $object_hash ) ) ) {
                throw new RuntimeException( 'Incremental snapshot object checksum mismatch.' );
            }
            $total += $object_bytes;
        }
        if ( $total !== $catalog['size_bytes'] || ! hash_equals( $catalog['sha256'], hash_final( $whole ) ) ) {
            throw new RuntimeException( 'Incremental snapshot archive checksum mismatch.' );
        }
    }

    private function store_object( $digest, $bytes ) {
        $path = $this->object_path( $digest );
        if ( file_exists( $path ) || is_link( $path ) ) {
            $this->verify_object_file( $path, $digest, strlen( $bytes ) );
            return;
        }
        $directory = dirname( $path );
        $this->ensure_directory( $directory );
        $temp = @tempnam( $directory, '.trasloco-object-' );
        if ( $temp === false ) { throw new RuntimeException( 'Cannot allocate an incremental object.' ); }
        @chmod( $temp, 0600 );
        $handle = @fopen( $temp, 'wb' );
        if ( ! $handle ) { @unlink( $temp ); throw new RuntimeException( 'Cannot write an incremental object.' ); }
        try {
            self::write( $handle, $bytes );
            if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) ) {
                throw new RuntimeException( 'Cannot synchronize an incremental object.' );
            }
        } catch ( Throwable $error ) {
            fclose( $handle ); @unlink( $temp ); throw $error;
        }
        fclose( $handle );
        if ( ! @link( $temp, $path ) ) {
            if ( is_file( $path ) && ! is_link( $path ) ) {
                $this->verify_object_file( $path, $digest, strlen( $bytes ) );
            } else {
                @unlink( $temp ); throw new RuntimeException( 'Cannot publish an incremental object safely.' );
            }
        }
        @unlink( $temp );
    }

    private function verify_object_file( $path, $digest, $size ) {
        $handle = $this->open_private_file( $path );
        $stat = fstat( $handle );
        if ( ! is_array( $stat ) || (int) $stat['size'] !== $size ) {
            fclose( $handle ); throw new RuntimeException( 'Existing incremental object failed verification.' );
        }
        $hash = hash_init( 'sha256' );
        while ( ! feof( $handle ) ) {
            $bytes = fread( $handle, 1024 * 1024 );
            if ( $bytes === false || ( $bytes === '' && ! feof( $handle ) ) ) {
                fclose( $handle ); throw new RuntimeException( 'Cannot verify existing incremental object.' );
            }
            if ( $bytes === '' ) { break; }
            hash_update( $hash, $bytes );
        }
        fclose( $handle );
        if ( ! hash_equals( $digest, hash_final( $hash ) ) ) {
            throw new RuntimeException( 'Existing incremental object failed verification.' );
        }
    }

    /** Open an unchanged, single-link regular file without following a path swap. */
    private function open_private_file( $path ) {
        clearstatcache( true, $path );
        $before = @lstat( $path );
        if ( ! is_array( $before ) || is_link( $path ) || ! is_file( $path ) ||
            ( isset( $before['nlink'] ) && $before['nlink'] !== 1 ) ) {
            throw new RuntimeException( 'Incremental storage file is missing or unsafe.' );
        }
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) { throw new RuntimeException( 'Cannot open an incremental storage file.' ); }
        $opened = fstat( $handle ); clearstatcache( true, $path ); $current = @lstat( $path );
        if ( ! self::same_file( $before, $opened ) || ! self::same_file( $before, $current ) || is_link( $path ) ||
            ( isset( $opened['nlink'] ) && $opened['nlink'] !== 1 ) ) {
            fclose( $handle ); throw new RuntimeException( 'Incremental storage file changed while opening.' );
        }
        return $handle;
    }

    private function publish_json( $path, $data ) {
        $bytes = json_encode( $data, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_CATALOG_BYTES ) {
            throw new RuntimeException( 'Incremental snapshot catalog exceeds its size limit.' );
        }
        $temp = @tempnam( dirname( $path ), '.trasloco-catalog-' );
        if ( $temp === false ) { throw new RuntimeException( 'Cannot allocate incremental catalog.' ); }
        @chmod( $temp, 0600 );
        $handle = @fopen( $temp, 'wb' );
        if ( ! $handle ) { @unlink( $temp ); throw new RuntimeException( 'Cannot write incremental catalog.' ); }
        try {
            self::write( $handle, $bytes );
            if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) ) {
                throw new RuntimeException( 'Cannot synchronize incremental catalog.' );
            }
        } catch ( Throwable $error ) { fclose( $handle ); @unlink( $temp ); throw $error; }
        fclose( $handle );
        if ( ! @link( $temp, $path ) ) { @unlink( $temp ); throw new RuntimeException( 'Cannot publish incremental catalog without replacement.' ); }
        @unlink( $temp );
    }

    private function remove_unreferenced_objects( $live ) {
        $objects = $this->object_dir();
        $removed = array();
        foreach ( new DirectoryIterator( $objects ) as $shard ) {
            if ( $shard->isDot() ) { continue; }
            if ( $shard->isLink() || ! $shard->isDir() || ! preg_match( '/^[a-f0-9]{2}$/D', $shard->getFilename() ) ) {
                throw new RuntimeException( 'Incremental object store contains an unsafe directory.' );
            }
            foreach ( new DirectoryIterator( $shard->getPathname() ) as $object ) {
                if ( $object->isDot() ) { continue; }
                $name = $object->getFilename();
                if ( strpos( $name, '.trasloco-object-' ) === 0 && $object->isFile() && ! $object->isLink() ) {
                    if ( ! @unlink( $object->getPathname() ) ) { throw new RuntimeException( 'Cannot remove an abandoned object temporary.' ); }
                    continue;
                }
                $digest = $name;
                if ( $object->isLink() || ! $object->isFile() || ! preg_match( '/^[a-f0-9]{64}$/D', $name ) ||
                    substr( $digest, 0, 2 ) !== $shard->getFilename() ) {
                    throw new RuntimeException( 'Incremental object store contains an unsafe entry.' );
                }
                if ( isset( $live[ $digest ] ) ) { continue; }
                if ( ! @unlink( $object->getPathname() ) ) { throw new RuntimeException( 'Cannot remove an unreferenced incremental object.' ); }
                $removed[] = $digest;
            }
            if ( count( scandir( $shard->getPathname() ) ) === 2 ) { @rmdir( $shard->getPathname() ); }
        }
        return $removed;
    }

    private function unique_object_count( $chunks ) {
        $seen = array();
        foreach ( $chunks as $chunk ) { $seen[ $chunk['sha256'] ] = true; }
        return count( $seen );
    }

    private function object_path( $digest ) {
        if ( ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
            throw new InvalidArgumentException( 'Invalid incremental object digest.' );
        }
        return $this->object_dir() . DIRECTORY_SEPARATOR . substr( $digest, 0, 2 ) . DIRECTORY_SEPARATOR . $digest;
    }

    private function catalog_path( $archive ) {
        return $this->catalog_dir() . DIRECTORY_SEPARATOR . $this->archive_name( $archive ) . '.json';
    }

    private function object_dir() { return $this->root . DIRECTORY_SEPARATOR . 'objects'; }
    private function catalog_dir() { return $this->root . DIRECTORY_SEPARATOR . 'catalogs'; }
    private function capture_dir() { return $this->root . DIRECTORY_SEPARATOR . 'pending'; }

    private function capture_journal_path( $job_id ) {
        return $this->capture_dir() . DIRECTORY_SEPARATOR . 'backup-' . (int) $job_id . '.jsonl';
    }

    private function open_capture_journal( $path, $header ) {
        clearstatcache( true, $path );
        if ( ! file_exists( $path ) && ! is_link( $path ) ) {
            $temporary = $path . '.tmp-' . bin2hex( random_bytes( 8 ) );
            $handle = @fopen( $temporary, 'x+b' );
            if ( ! $handle ) { throw new RuntimeException( 'Cannot create incremental capture journal.' ); }
            @chmod( $temporary, 0600 );
            try {
                self::write( $handle, json_encode( $header, JSON_UNESCAPED_SLASHES ) . "\n" );
                if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! @fsync( $handle ) ) ) {
                    throw new RuntimeException( 'Cannot synchronize incremental capture journal header.' );
                }
            } catch ( Throwable $error ) {
                fclose( $handle ); @unlink( $temporary ); throw $error;
            }
            fclose( $handle );
            // The store lock serializes capture creation. Rename publishes the
            // complete header atomically; a crash before it leaves only a
            // private temporary file that cannot be mistaken for a journal.
            clearstatcache( true, $path );
            if ( file_exists( $path ) || is_link( $path ) || ! @rename( $temporary, $path ) ) {
                @unlink( $temporary );
                clearstatcache( true, $path );
                if ( ! file_exists( $path ) || is_link( $path ) ) {
                    throw new RuntimeException( 'Cannot publish incremental capture journal.' );
                }
            }
        }
        clearstatcache( true, $path );
        $before = @lstat( $path );
        if ( ! is_array( $before ) || is_link( $path ) || ! is_file( $path ) ||
            ( isset( $before['nlink'] ) && $before['nlink'] !== 1 ) ) {
            throw new RuntimeException( 'Incremental capture journal is unsafe.' );
        }
        $handle = @fopen( $path, 'r+b' );
        if ( ! $handle ) { throw new RuntimeException( 'Cannot open incremental capture journal.' ); }
        $opened = fstat( $handle ); clearstatcache( true, $path ); $current = @lstat( $path );
        if ( ! self::same_file( $before, $opened ) || ! self::same_file( $before, $current ) || is_link( $path ) ||
            ( isset( $opened['nlink'] ) && $opened['nlink'] !== 1 ) ) {
            fclose( $handle ); throw new RuntimeException( 'Incremental capture journal changed while opening.' );
        }
        return $handle;
    }

    private function remove_capture_journal( $path ) {
        clearstatcache( true, $path );
        if ( ! file_exists( $path ) && ! is_link( $path ) ) { return true; }
        $stat = @lstat( $path );
        if ( ! is_array( $stat ) || is_link( $path ) || ! is_file( $path ) ||
            ( isset( $stat['nlink'] ) && $stat['nlink'] !== 1 ) || ! @unlink( $path ) ) {
            throw new RuntimeException( 'Cannot safely remove incremental capture journal.' );
        }
        return true;
    }

    private function ensure_root() {
        $this->ensure_directory( $this->root );
        $this->ensure_directory( $this->object_dir() );
        $this->ensure_directory( $this->catalog_dir() );
        $this->ensure_directory( $this->capture_dir() );
    }

    private function ensure_directory( $path ) {
        if ( is_link( $path ) ) { throw new RuntimeException( 'Incremental storage must not contain symbolic links.' ); }
        if ( ! is_dir( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) {
            throw new RuntimeException( 'Cannot create private incremental storage.' );
        }
        if ( is_link( $path ) || ! is_dir( $path ) || ! is_writable( $path ) ) {
            throw new RuntimeException( 'Private incremental storage is unavailable.' );
        }
        @chmod( $path, 0700 );
    }

    private function locked( $callback ) {
        $path = $this->root . DIRECTORY_SEPARATOR . '.store.lock';
        clearstatcache( true, $path );
        if ( ! file_exists( $path ) && ! is_link( $path ) ) { $handle = @fopen( $path, 'x+b' ); }
        else { $handle = false; }
        if ( ! $handle ) {
            clearstatcache( true, $path );
            $before = @lstat( $path );
            if ( ! is_array( $before ) || is_link( $path ) || ! is_file( $path ) ) {
                throw new RuntimeException( 'Incremental store lock path is unsafe.' );
            }
            $handle = @fopen( $path, 'r+b' );
        }
        if ( ! $handle ) { throw new RuntimeException( 'Cannot open incremental store lock.' ); }
        $opened = fstat( $handle ); clearstatcache( true, $path ); $current = @lstat( $path );
        if ( ! is_array( $opened ) || ! is_array( $current ) || is_link( $path ) || ! is_file( $path ) ||
            $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] ||
            ( isset( $opened['nlink'] ) && $opened['nlink'] !== 1 ) ) {
            fclose( $handle ); throw new RuntimeException( 'Incremental store lock is not a private regular file.' );
        }
        @chmod( $path, 0600 );
        if ( ! flock( $handle, LOCK_EX ) ) { fclose( $handle ); throw new RuntimeException( 'Cannot lock incremental storage.' ); }
        try { return call_user_func( $callback ); }
        finally { flock( $handle, LOCK_UN ); fclose( $handle ); }
    }

    private static function read_exact( $handle, $length, $allow_eof = false ) {
        $bytes = '';
        while ( strlen( $bytes ) < $length ) {
            $part = fread( $handle, $length - strlen( $bytes ) );
            if ( $part === false || ( $part === '' && ! feof( $handle ) ) ) {
                throw new RuntimeException( 'Cannot read source archive bytes.' );
            }
            if ( $part === '' ) { break; }
            $bytes .= $part;
        }
        if ( ! $allow_eof && strlen( $bytes ) !== $length ) { throw new RuntimeException( 'Source archive ended unexpectedly.' ); }
        return $bytes;
    }

    private static function same_file( $expected, $actual ) {
        if ( ! is_array( $expected ) || ! is_array( $actual ) ) { return false; }
        foreach ( array( 'size','mtime','ctime','ino','dev' ) as $key ) {
            if ( ! isset( $expected[ $key ], $actual[ $key ] ) || (int) $expected[ $key ] !== (int) $actual[ $key ] ) { return false; }
        }
        return true;
    }

    private static function write( $handle, $bytes ) {
        $offset = 0; $length = strlen( $bytes );
        while ( $offset < $length ) {
            $written = fwrite( $handle, substr( $bytes, $offset ) );
            if ( $written === false || $written === 0 ) { throw new RuntimeException( 'Cannot write incremental archive data.' ); }
            $offset += $written;
        }
    }
}
