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
            ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\\.wpress(?:\\.manifest\\.json)?$/D', $key ) ||
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
            $written = stream_copy_to_stream( $handle, $out );
            if ( $written === false || ! fflush( $out ) ) {
                throw new RuntimeException( 'Cannot complete stored stream.' );
            }
        } finally {
            fclose( $out );
        }
        if ( ! rename( $temp, $path ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot publish archive atomically.' );
        }
        return $written;
    }

    public function open_read( $key ) {
        $path = $this->path( $key );
        $handle = @fopen( $path, 'rb' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Archive cannot be opened.' );
        }
        return $handle;
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
