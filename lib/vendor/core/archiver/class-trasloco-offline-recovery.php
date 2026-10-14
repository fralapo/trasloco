<?php
/**
 * Small, standalone rescue reader for broken WordPress installations.
 * No WordPress bootstrap, database access, SQL execution or live-site writes.
 * Extracts only archive-root package.json/database.sql/multisite.json into
 * a newly created 0700 directory for manual disaster recovery.
 */
class Trasloco_Offline_Recovery {
    const HEADER_BYTES = 4377;
    const MAX_ENTRIES = 1000000;
    const MAX_MANIFEST = 33554432;

    public static function inspect( $archive ) {
        if ( ! is_string( $archive ) || ! preg_match( '/\.wpress$/D', $archive ) ||
            ! is_file( $archive ) || ! is_readable( $archive ) || is_link( $archive ) ) {
            throw new RuntimeException( 'Use a readable regular .wpress archive.' );
        }
        clearstatcache( true, $archive );
        $length = filesize( $archive );
        if ( $length === false || $length < self::HEADER_BYTES ) {
            throw new RuntimeException( 'Archive is too small.' );
        }
        $integrity = 'unverified_legacy';
        $sidecar = $archive . '.manifest.json';
        if ( file_exists( $sidecar ) ) {
            if ( ! is_file( $sidecar ) || is_link( $sidecar ) ||
                filesize( $sidecar ) > self::MAX_MANIFEST ) {
                throw new RuntimeException( 'Unsafe integrity manifest.' );
            }
            $body = @file_get_contents( $sidecar );
            $manifest = is_string( $body ) ? json_decode( $body, true ) : null;
            // The preserved 2.0 branch wrote a distinct schema-1 sidecar.
            // Normalize only its exact whole-file SHA-256 contract.
            if ( is_array( $manifest ) && ! isset( $manifest['manifest_version'] ) &&
                isset( $manifest['schema'], $manifest['format'], $manifest['archive'],
                       $manifest['bytes'], $manifest['sha256'] ) &&
                $manifest['schema'] === 1 && $manifest['format'] === 'wpress' &&
                $manifest['archive'] === basename( $archive ) &&
                is_int( $manifest['bytes'] ) && $manifest['bytes'] >= 0 &&
                is_string( $manifest['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/D', $manifest['sha256'] ) ) {
                $manifest = array(
                    'manifest_version' => 1,
                    'algorithm' => 'sha256',
                    'size_bytes' => $manifest['bytes'],
                    'sha256' => $manifest['sha256'],
                );
            }
            if ( ! is_array( $manifest ) ||
                ! isset( $manifest['manifest_version'], $manifest['algorithm'],
                          $manifest['size_bytes'], $manifest['sha256'] ) ||
                ! in_array( $manifest['manifest_version'], array( 1, 2 ), true ) ||
                $manifest['algorithm'] !== 'sha256' ||
                ! is_int( $manifest['size_bytes'] ) || $manifest['size_bytes'] !== $length ||
                ! is_string( $manifest['sha256'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $manifest['sha256'] ) ||
                ! hash_equals( $manifest['sha256'], hash_file( 'sha256', $archive ) ) ) {
                throw new RuntimeException( 'Backup checksum or manifest is invalid.' );
            }
            $integrity = 'sha256_verified';
        }

        $in = @fopen( $archive, 'rb' );
        if ( $in === false ) {
            throw new RuntimeException( 'Cannot open the rescue archive.' );
        }
        $files = array();
        $position = 0;
        $entries = 0;
        $selected = array( 'database.sql' => true, 'package.json' => true, 'multisite.json' => true );
        try {
            while ( $position < $length - self::HEADER_BYTES ) {
                if ( fseek( $in, $position, SEEK_SET ) !== 0 ) {
                    throw new RuntimeException( 'Cannot seek in the archive.' );
                }
                $header = fread( $in, self::HEADER_BYTES );
                if ( ! is_string( $header ) || strlen( $header ) !== self::HEADER_BYTES ) {
                    throw new RuntimeException( 'Archive header is incomplete.' );
                }
                $name = trim( substr( $header, 0, 255 ), "\0 " );
                $size_string = trim( substr( $header, 255, 14 ), "\0 " );
                $folder = substr( $header, 281, 4096 );
                $null = strpos( $folder, "\0" );
                if ( $null !== false ) {
                    $folder = substr( $folder, 0, $null );
                } else {
                    $folder = substr( $folder, 0, -8 );
                }
                $folder = str_replace( '\\', '/', trim( $folder ) );
                if ( $folder === '.' ) {
                    $folder = '';
                }
                // Match the live restore preflight. A standalone rescue
                // report must not call a malformed archive safe just because
                // it only extracts package.json and database.sql.
                $components = $folder === '' ? array() : explode( '/', $folder );
                if ( $name === '' || $name === '.' || $name === '..' ||
                    strpbrk( $name, "/\\\0" ) !== false ||
                    preg_match( '/[\x00-\x1f\x7f]/', $name . $folder ) ||
                    preg_match( '#^(/|[a-zA-Z]:)#', $folder ) ||
                    in_array( '..', $components, true ) ||
                    in_array( '.', $components, true ) ||
                    in_array( '', $components, true ) ||
                    ! ctype_digit( $size_string ) ) {
                    throw new RuntimeException( 'Archive has an unsafe or malformed entry.' );
                }
                $size = filter_var( $size_string, FILTER_VALIDATE_INT, array(
                    'options' => array( 'min_range' => 0 ),
                ) );
                if ( $size === false ||
                    $size > $length - self::HEADER_BYTES - $position - self::HEADER_BYTES ) {
                    throw new RuntimeException( 'Archive has an impossible file length.' );
                }
                if ( $folder === '' && isset( $selected[ $name ] ) ) {
                    if ( isset( $files[ $name ] ) ) {
                        throw new RuntimeException( 'Archive contains duplicate rescue metadata.' );
                    }
                    $files[ $name ] = array( 'offset' => $position + self::HEADER_BYTES, 'size' => $size );
                }
                $position += self::HEADER_BYTES + $size;
                $entries++;
                if ( $entries > self::MAX_ENTRIES ) {
                    throw new RuntimeException( 'Archive has too many entries.' );
                }
            }
            if ( $position !== $length - self::HEADER_BYTES ||
                fseek( $in, $position, SEEK_SET ) !== 0 ) {
                throw new RuntimeException( 'Archive ends at an invalid offset.' );
            }
            $trailer = fread( $in, self::HEADER_BYTES );
            if ( ! is_string( $trailer ) || strlen( $trailer ) !== self::HEADER_BYTES ||
                trim( substr( $trailer, 0, 255 ), "\0" ) !== '' ) {
                throw new RuntimeException( 'Archive terminator is missing.' );
            }
        } finally {
            fclose( $in );
        }
        if ( ! isset( $files['package.json'] ) ) {
            throw new RuntimeException( 'Archive has no package.json metadata.' );
        }
        return array( 'integrity' => $integrity, 'entries' => $entries, 'files' => $files );
    }


    /**
     * Stage WordPress content OUTSIDE the live site. Paths must be absent.
     * Files are capped to avoid exhausting disk space with untrusted archives.
     * This never executes PHP/SQL or modifies WordPress.
     */
    public static function stage_content( $archive, $destination, $max_bytes = 1073741824,
        $require_fsync = false ) {
        // Full entry preflight (including paths and archive terminator) before writes.
        self::inspect( $archive );
        if ( ! is_int( $max_bytes ) || $max_bytes < 1 || ! is_bool( $require_fsync ) ||
            ! is_string( $destination ) || file_exists( $destination ) || is_link( $destination ) ||
            ! is_dir( dirname( $destination ) ) || ! mkdir( $destination, 0700 ) ) {
            throw new RuntimeException( 'Cannot allocate private staging storage.' );
        }
        @chmod( $destination, 0700 );
        if ( $require_fsync ) {
            if ( ! function_exists( 'fsync' ) ) {
                @rmdir( $destination );
                throw new RuntimeException( 'Strict fsync recovery requires PHP fsync support.' );
            }
            self::sync_directory( dirname( $destination ), true );
        }
        $length = filesize( $archive );
        $input = @fopen( $archive, 'rb' );
        if ( $input === false ) {
            @rmdir( $destination );
            throw new RuntimeException( 'Cannot open archive for staged recovery.' );
        }
        $position = 0;
        $bytes = 0;
        $count = 0;
        $created_files = array();
        $created_directories = array();
        try {
            while ( $position < $length - self::HEADER_BYTES ) {
                if ( fseek( $input, $position, SEEK_SET ) !== 0 ) {
                    throw new RuntimeException( 'Cannot seek within archive.' );
                }
                $header = fread( $input, self::HEADER_BYTES );
                if ( ! is_string( $header ) || strlen( $header ) !== self::HEADER_BYTES ) {
                    throw new RuntimeException( 'Archive header changed during staging.' );
                }
                $name = trim( substr( $header, 0, 255 ), "\0 " );
                $size_str = trim( substr( $header, 255, 14 ), "\0 " );
                $folder = substr( $header, 281, 4096 );
                $nul = strpos( $folder, "\0" );
                $folder = $nul !== false ? substr( $folder, 0, $nul ) : substr( $folder, 0, -8 );
                $folder = str_replace( '\\', '/', trim( $folder ) );
                if ( $folder === '.' ) { $folder = ''; }
                // Root metadata is legal and is not a staged content path.
                // Empty segments remain forbidden inside non-root folders.
                $parts = $folder === '' ? array() : explode( '/', $folder );
                if ( $name === '' || $name === '.' || $name === '..' ||
                    strpbrk( $name, "/\\\0" ) !== false ||
                    preg_match( '/[\x00-\x1f\x7f]/', $name . $folder ) ||
                    preg_match( '#^(/|[A-Za-z]:)#', $folder ) ||
                    in_array( '..', $parts, true ) || in_array( '.', $parts, true ) ||
                    in_array( '', $parts, true ) ||
                    ! ctype_digit( $size_str ) ) {
                    throw new RuntimeException( 'Unsafe staged file path.' );
                }
                $size = filter_var( $size_str, FILTER_VALIDATE_INT, array(
                    'options' => array( 'min_range' => 0 ),
                ) );
                if ( $size === false || $size > $length - self::HEADER_BYTES - $position - self::HEADER_BYTES ) {
                    throw new RuntimeException( 'Invalid staged file length.' );
                }
                if ( ! empty( $parts ) &&
                    in_array( $parts[0], array( 'uploads', 'plugins', 'themes', 'mu-plugins' ), true ) ) {
                    if ( $bytes > $max_bytes - $size ) {
                        throw new RuntimeException( 'Staged contents exceed the configured safety limit.' );
                    }
                    $cursor = $destination;
                    foreach ( $parts as $part ) {
                        if ( $part === '' || $part === '.' || $part === '..' ) {
                            throw new RuntimeException( 'Invalid staged directory.' );
                        }
                        $cursor .= DIRECTORY_SEPARATOR . $part;
                        if ( is_link( $cursor ) ) {
                            throw new RuntimeException( 'Symlink in staging directory.' );
                        }
                        if ( ! is_dir( $cursor ) ) {
                            if ( ! mkdir( $cursor, 0700 ) ) {
                                throw new RuntimeException( 'Cannot create private staging directory.' );
                            }
                            $created_directories[] = $cursor;
                            self::sync_directory( dirname( $cursor ), $require_fsync );
                        }
                    }
                    $target = $cursor . DIRECTORY_SEPARATOR . $name;
                    if ( file_exists( $target ) || is_link( $target ) ) {
                        throw new RuntimeException( 'Duplicate archive path cannot be staged.' );
                    }
                    $out = @fopen( $target, 'xb' );
                    if ( $out === false ) {
                        throw new RuntimeException( 'Cannot create staged content file.' );
                    }
                    $created_files[] = $target;
                    @chmod( $target, 0600 );
                    try {
                        $left = $size;
                        while ( $left > 0 ) {
                            $part = fread( $input, min( 1048576, $left ) );
                            if ( ! is_string( $part ) || $part === '' ) {
                                throw new RuntimeException( 'Archive changed during staging.' );
                            }
                            $offset = 0;
                            while ( $offset < strlen( $part ) ) {
                                $written = fwrite( $out, substr( $part, $offset ) );
                                if ( $written === false || $written === 0 ) {
                                    throw new RuntimeException( 'Cannot write staged content.' );
                                }
                                $offset += $written;
                            }
                            $left -= strlen( $part );
                        }
                        self::sync_stream( $out, $require_fsync );
                    } finally {
                        fclose( $out );
                    }
                    self::sync_directory( dirname( $target ), $require_fsync );
                    $bytes += $size;
                    $count++;
                }
                $position += self::HEADER_BYTES + $size;
            }
            if ( $position !== $length - self::HEADER_BYTES ) {
                throw new RuntimeException( 'Staging ended at an invalid archive offset.' );
            }
        } catch ( Throwable $e ) {
            // No partial recovery tree is ever presented as a complete stage.
            // Everything underneath destination was created by this function.
            foreach ( array_reverse( $created_files ) as $file ) {
                if ( ! is_link( $file ) ) { @unlink( $file ); }
            }
            foreach ( array_reverse( $created_directories ) as $folder ) {
                if ( ! is_link( $folder ) ) { @rmdir( $folder ); }
            }
            @rmdir( $destination );
            throw $e;
        } finally {
            fclose( $input );
        }
        self::sync_directory( $destination, $require_fsync );
        return array( 'files_staged' => $count, 'bytes_staged' => $bytes );
    }

    /** Flush file bytes and metadata before reporting a private stage as ready. */
    private static function sync_stream( $stream, $required ) {
        if ( ! fflush( $stream ) ||
            ( $required && ( ! function_exists( 'fsync' ) || ! @fsync( $stream ) ) ) ) {
            throw new RuntimeException( 'Staged recovery data could not be synchronized.' );
        }
    }

    /** Flush a directory entry after publishing files or directories inside it. */
    private static function sync_directory( $directory, $required ) {
        if ( ! $required ) {
            return;
        }
        $handle = @fopen( $directory, 'r' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Staging directory cannot be synchronized.' );
        }
        try {
            if ( ! function_exists( 'fsync' ) || ! @fsync( $handle ) ) {
                throw new RuntimeException( 'Staging directory cannot be synchronized.' );
            }
        } finally {
            fclose( $handle );
        }
    }

    /** The destination must not exist: never overwrite an operator's files. */
    public static function extract_metadata( $archive, $destination ) {
        $report = self::inspect( $archive );
        if ( ! is_string( $destination ) || file_exists( $destination ) ||
            is_link( $destination ) || ! is_dir( dirname( $destination ) ) ||
            ! mkdir( $destination, 0700 ) ) {
            throw new RuntimeException( 'Cannot safely create private recovery directory.' );
        }
        @chmod( $destination, 0700 );
        $in = fopen( $archive, 'rb' );
        try {
            foreach ( $report['files'] as $name => $info ) {
                $out = fopen( $destination . DIRECTORY_SEPARATOR . $name, 'xb' );
                if ( $out === false ) {
                    throw new RuntimeException( 'Cannot create private recovery file.' );
                }
                @chmod( $destination . DIRECTORY_SEPARATOR . $name, 0600 );
                try {
                    if ( fseek( $in, $info['offset'], SEEK_SET ) !== 0 ) {
                        throw new RuntimeException( 'Cannot seek to selected recovery file.' );
                    }
                    $left = $info['size'];
                    while ( $left > 0 ) {
                        $data = fread( $in, min( $left, 1048576 ) );
                        if ( $data === false || $data === '' ) {
                            throw new RuntimeException( 'Recovery file was truncated.' );
                        }
                        $offset = 0;
                        $length = strlen( $data );
                        while ( $offset < $length ) {
                            $written = fwrite( $out, substr( $data, $offset ) );
                            if ( $written === false || $written === 0 ) {
                                throw new RuntimeException( 'Cannot write private recovery data.' );
                            }
                            $offset += $written;
                        }
                        $left -= $length;
                    }
                    if ( ! fflush( $out ) ) {
                        throw new RuntimeException( 'Cannot flush recovery file.' );
                    }
                } finally {
                    fclose( $out );
                }
            }
        } catch ( Throwable $e ) {
            foreach ( array_keys( $report['files'] ) as $name ) {
                @unlink( $destination . DIRECTORY_SEPARATOR . $name );
            }
            @rmdir( $destination );
            throw $e;
        } finally {
            fclose( $in );
        }
        return $report;
    }
}
