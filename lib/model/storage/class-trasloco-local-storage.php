<?php
/**
 * Filesystem implementation with basename-only keys, no symlinks, and atomic
 * writes on the same filesystem. Remote adapters must offer the same contract.
 */
class Trasloco_Local_Storage implements Trasloco_Storage_Adapter {
    private $root;

    public function __construct( $root ) {
        if ( ! is_string( $root ) || ! is_dir( $root ) || ! is_writable( $root ) ) {
            throw new InvalidArgumentException( 'Backup directory does not exist or is not writable.' );
        }
        $canonical = realpath( $root );
        if ( $canonical === false ) {
            throw new InvalidArgumentException( 'Invalid backup directory.' );
        }
        $this->root = $canonical;
    }

    private function path( $key ) {
        if ( ! is_string( $key ) || strlen( $key ) > 220 ||
            ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\\.wpress(?:\\.enc(?:\\.manifest\\.json)?|\\.manifest\\.json)?$/D', $key ) ||
            strpos( $key, '..' ) !== false ) {
            throw new InvalidArgumentException( 'Invalid storage key.' );
        }
        $path = $this->root . DIRECTORY_SEPARATOR . $key;
        if ( is_link( $path ) ) {
            throw new InvalidArgumentException( 'Symbolic-link backups are not allowed.' );
        }
        return $path;
    }

    /** $handle must be an open readable stream. */
    public function put_stream( $key, $handle ) {
        $path = $this->path( $key );
        if ( ! is_resource( $handle ) ) {
            throw new InvalidArgumentException( 'Expected a readable stream.' );
        }
        $temp = tempnam( $this->root, '.tr-writing-' );
        if ( $temp === false ) {
            throw new RuntimeException( 'Cannot allocate temporary storage.' );
        }
        $out = @fopen( $temp, 'wb' );
        if ( $out === false ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot open temporary storage.' );
        }
        try {
            @chmod( $temp, 0600 );
            $written = @stream_copy_to_stream( $handle, $out );
            if ( $written === false || ! fflush( $out ) ) {
                throw new RuntimeException( 'Cannot complete stored stream.' );
            }
        } catch ( Throwable $error ) {
            // Never leave incomplete, potentially sensitive backup bytes behind.
            fclose( $out );
            @unlink( $temp );
            throw $error;
        }
        fclose( $out );
        // Atomic no-overwrite publication: hard-link creation fails if the
        // destination already exists (unlike rename which silently replaces).
        // Fail closed on filesystems that cannot create this same-dir link.
        if ( ! @link( $temp, $path ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Archive already exists or storage cannot publish it safely.' );
        }
        @unlink( $temp );
        return $written;
    }

    public function open_read( $key, $max_bytes = null ) {
        $path = $this->path( $key );
        if ( $max_bytes !== null && ( ! is_int( $max_bytes ) || $max_bytes < 0 || filesize( $path ) > $max_bytes ) ) {
            throw new RuntimeException( 'Stored object exceeds the requested size limit.' );
        }
        $handle = @fopen( $path, 'rb' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Archive cannot be opened.' );
        }
        return $handle;
    }

    public function open_read_range( $key, $offset, $length, $total_bytes ) {
        $path = $this->path( $key );
        if ( ! is_int( $offset ) || ! is_int( $length ) || ! is_int( $total_bytes ) ||
            $offset < 0 || $length <= 0 || $offset > $total_bytes || $length > $total_bytes - $offset ) {
            throw new InvalidArgumentException( 'Invalid or stale storage byte range.' );
        }
        if ( ! is_file( $path ) || filesize( $path ) !== $total_bytes ) {
            throw new RuntimeException( 'Stored object size changed after manifest validation.' );
        }
        $input = @fopen( $path, 'rb' );
        $range = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( $input === false || $range === false ) {
            if ( is_resource( $input ) ) { fclose( $input ); }
            if ( is_resource( $range ) ) { fclose( $range ); }
            throw new RuntimeException( 'Stored object range cannot be opened.' );
        }
        try {
            if ( fseek( $input, $offset, SEEK_SET ) !== 0 ||
                stream_copy_to_stream( $input, $range, $length ) !== $length || ! rewind( $range ) ) {
                throw new RuntimeException( 'Stored object range is incomplete.' );
            }
            return $range;
        } catch ( Throwable $error ) {
            fclose( $range );
            throw $error;
        } finally {
            fclose( $input );
        }
    }

    public function exists( $key ) {
        return is_file( $this->path( $key ) );
    }

    public function delete( $key ) {
        $path = $this->path( $key );
        return ! file_exists( $path ) || @unlink( $path );
    }

    public function enumerate() {
        $items = array();
        foreach ( new DirectoryIterator( $this->root ) as $file ) {
            if ( $file->isDot() || $file->isLink() || ! $file->isFile() ) {
                continue;
            }
            try {
                $key = $file->getFilename();
                $this->path( $key );
                $items[] = $key;
            } catch ( InvalidArgumentException $ignored ) {
                // Unexpected files must never enter storage inventory.
            }
        }
        sort( $items, SORT_STRING );
        return $items;
    }
}
