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
                if ( $name === '' || $name === '..' ||
                    strpbrk( $name, "/\\\0" ) !== false ||
                    preg_match( '/[\x00-\x1f]/', $name . $folder ) ||
                    preg_match( '#^(/|[a-zA-Z]:)#', $folder ) ||
                    in_array( '..', explode( '/', $folder ), true ) ||
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
