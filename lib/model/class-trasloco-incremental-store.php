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
    public function capture( $archive ) {
        $this->ensure_root();
        return $this->locked( function () use ( $archive ) {
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

    private function ensure_root() {
        $this->ensure_directory( $this->root );
        $this->ensure_directory( $this->object_dir() );
        $this->ensure_directory( $this->catalog_dir() );
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
