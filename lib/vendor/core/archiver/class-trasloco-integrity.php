<?php
/**
 * Versioned local archive integrity manifest.
 *
 * A manifest detects accidental corruption; it does not authenticate the
 * origin of an untrusted archive. Version 1 contains no credentials or URLs.
 * Sidecars are an additive feature, so legacy .wpress archives still work.
 */
class Trasloco_Integrity {
    const VERSION = 1;
    const CHUNKED_VERSION = 2;
    const CHUNK_BYTES = 4194304;
    const MAX_MANIFEST_BYTES = 33554432;

    public static function manifest_path( $archive ) {
        return $archive . '.manifest.json';
    }

    /**
     * Normalize the schema-1 sidecar emitted by the preserved 2.0 branch.
     * New writes continue using this branch's manifest_version formats.
     */
    private static function normalize_feature_v1_manifest( $data, $archive ) {
        if ( ! is_array( $data ) || ! isset( $data['schema'], $data['format'], $data['archive'], $data['bytes'], $data['sha256'] ) ||
            $data['schema'] !== 1 || $data['format'] !== 'wpress' ||
            $data['archive'] !== basename( $archive ) || ! is_int( $data['bytes'] ) || $data['bytes'] < 0 ||
            ! is_string( $data['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            return null;
        }
        return array(
            'manifest_version' => self::VERSION,
            'algorithm' => 'sha256',
            'size_bytes' => $data['bytes'],
            'sha256' => $data['sha256'],
        );
    }

    /** Publish manifest atomically only after an archive is fully written. */
    public static function create( $archive ) {
        if ( ! is_file( $archive ) || ! is_readable( $archive ) ) {
            throw new Trasloco_Archive_Exception( 'Archive is missing or unreadable.' );
        }
        clearstatcache( true, $archive );
        $size = filesize( $archive );
        $digest = hash_file( 'sha256', $archive );
        if ( $size === false || $digest === false ) {
            throw new Trasloco_Archive_Exception( 'Cannot calculate archive checksum.' );
        }
        $body = json_encode( array(
            'manifest_version' => self::VERSION,
            'algorithm'        => 'sha256',
            'size_bytes'       => (int) $size,
            'sha256'           => $digest,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $body ) ) {
            throw new Trasloco_Archive_Exception( 'Cannot encode integrity manifest.' );
        }
        $manifest = self::manifest_path( $archive );
        $temp = $manifest . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
        if ( file_put_contents( $temp, $body, LOCK_EX ) !== strlen( $body ) || ! rename( $temp, $manifest ) ) {
            @unlink( $temp );
            throw new Trasloco_Archive_Exception( 'Cannot save integrity manifest.' );
        }
        return $manifest;
    }


    /**
     * New additive format. Version 2 carries independently verifiable 4-MiB
     * chunk checksums plus the same whole-file SHA-256. Constant-memory
     * reads make it suitable for large backups and bounded cron workers.
     */
    public static function create_chunked( $archive ) {
        if ( ! is_file( $archive ) || ! is_readable( $archive ) || is_link( $archive ) ) {
            throw new Trasloco_Archive_Exception( 'Archive is missing, unreadable or is a symbolic link.' );
        }
        clearstatcache( true, $archive );
        $size = filesize( $archive );
        $input = @fopen( $archive, 'rb' );
        if ( $size === false || $input === false ) {
            throw new Trasloco_Archive_Exception( 'Cannot read archive for checksums.' );
        }
        $whole = hash_init( 'sha256' );
        $chunks = array();
        $processed = 0;
        try {
            while ( $processed < $size ) {
                $needed = min( self::CHUNK_BYTES, $size - $processed );
                $part = '';
                while ( strlen( $part ) < $needed ) {
                    $read = fread( $input, $needed - strlen( $part ) );
                    if ( $read === false || $read === '' ) {
                        throw new Trasloco_Archive_Exception( 'Archive changed during checksum creation.' );
                    }
                    $part .= $read;
                }
                hash_update( $whole, $part );
                $chunks[] = hash( 'sha256', $part );
                $processed += strlen( $part );
                if ( count( $chunks ) > 400000 ) {
                    throw new Trasloco_Archive_Exception( 'Archive exceeds the chunk manifest limit.' );
                }
            }
            // A growing archive must never be represented by a stale manifest.
            if ( fread( $input, 1 ) !== '' ) {
                throw new Trasloco_Archive_Exception( 'Archive changed during checksum creation.' );
            }
        } finally {
            fclose( $input );
        }
        clearstatcache( true, $archive );
        if ( filesize( $archive ) !== $size ) {
            throw new Trasloco_Archive_Exception( 'Archive size changed during checksum creation.' );
        }
        $manifest = array(
            'manifest_version' => self::CHUNKED_VERSION,
            'algorithm' => 'sha256',
            'size_bytes' => $size,
            'sha256' => hash_final( $whole ),
            'chunk_bytes' => self::CHUNK_BYTES,
            'chunks' => $chunks,
        );
        $encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_MANIFEST_BYTES ) {
            throw new Trasloco_Archive_Exception( 'Cannot encode chunk manifest within allowed limits.' );
        }
        return self::atomic_publish( $archive, $encoded );
    }

    private static function atomic_publish( $archive, $encoded ) {
        $manifest = self::manifest_path( $archive );
        $temp = $manifest . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
        if ( file_put_contents( $temp, $encoded, LOCK_EX ) !== strlen( $encoded ) ||
            ! rename( $temp, $manifest ) ) {
            @unlink( $temp );
            throw new Trasloco_Archive_Exception( 'Cannot save integrity manifest.' );
        }
        return $manifest;
    }

    /** Validated v2 manifest, or null for v1/missing. Reject invalid v2. */
    public static function chunked_manifest( $archive ) {
        $filename = self::manifest_path( $archive );
        if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
            return null;
        }
        $length = filesize( $filename );
        if ( $length === false || $length > self::MAX_MANIFEST_BYTES ) {
            throw new Trasloco_Archive_Exception( 'Integrity manifest exceeds the size limit.' );
        }
        $body = @file_get_contents( $filename );
        $data = is_string( $body ) ? json_decode( $body, true ) : null;
        if ( ! is_array( $data ) ) {
            throw new Trasloco_Archive_Exception( 'Malformed integrity manifest.' );
        }
        if ( ! isset( $data['manifest_version'] ) ) {
            if ( self::normalize_feature_v1_manifest( $data, $archive ) !== null ) {
                return null;
            }
            throw new Trasloco_Archive_Exception( 'Malformed integrity manifest.' );
        }
        if ( $data['manifest_version'] !== self::CHUNKED_VERSION ) {
            return null;
        }
        if ( ! isset( $data['algorithm'], $data['size_bytes'], $data['sha256'],
                       $data['chunk_bytes'], $data['chunks'] ) ||
            $data['algorithm'] !== 'sha256' || ! is_int( $data['size_bytes'] ) ||
            $data['size_bytes'] < 0 || ! is_int( $data['chunk_bytes'] ) ||
            $data['chunk_bytes'] !== self::CHUNK_BYTES ||
            ! is_string( $data['sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ||
            ! is_array( $data['chunks'] ) ) {
            throw new Trasloco_Archive_Exception( 'Malformed chunk integrity manifest.' );
        }
        $count = intdiv( $data['size_bytes'], self::CHUNK_BYTES ) +
            ( $data['size_bytes'] % self::CHUNK_BYTES === 0 ? 0 : 1 );
        if ( $count > 400000 || $count !== count( $data['chunks'] ) ||
            array_keys( $data['chunks'] ) !== range( 0, count( $data['chunks'] ) - 1 ) && $count > 0 ) {
            throw new Trasloco_Archive_Exception( 'Invalid manifest chunk count.' );
        }
        foreach ( $data['chunks'] as $digest ) {
            if ( ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
                throw new Trasloco_Archive_Exception( 'Invalid chunk digest.' );
            }
        }
        $data['_manifest_sha256'] = hash( 'sha256', $body );
        return $data;
    }

    /**
     * Independently validate one chunk in bounded memory.
     * Used by the durable worker, which checkpoints its next chunk.
     */
    public static function verify_chunk( $archive, $manifest, $index ) {
        if ( ! is_array( $manifest ) || ! is_int( $index ) ||
            $index < 0 || $index >= count( $manifest['chunks'] ) ) {
            return false;
        }
        $handle = @fopen( $archive, 'rb' );
        if ( $handle === false ) {
            return false;
        }
        try {
            $offset = $index * self::CHUNK_BYTES;
            $remaining = min( self::CHUNK_BYTES, $manifest['size_bytes'] - $offset );
            if ( fseek( $handle, $offset, SEEK_SET ) !== 0 ) {
                return false;
            }
            $hash = hash_init( 'sha256' );
            while ( $remaining > 0 ) {
                $part = fread( $handle, min( 1048576, $remaining ) );
                if ( $part === false || $part === '' ) {
                    return false;
                }
                hash_update( $hash, $part );
                $remaining -= strlen( $part );
            }
            return hash_equals( $manifest['chunks'][ $index ], hash_final( $hash ) );
        } finally {
            fclose( $handle );
        }
    }

    /** Return a structured check result instead of claiming all archives valid. */
    public static function verify( $archive ) {
        $manifest = self::manifest_path( $archive );
        if ( ! is_file( $archive ) || ! is_readable( $archive ) ) {
            return array( 'status' => 'missing', 'message' => 'Backup archive is missing.' );
        }
        if ( ! is_file( $manifest ) || ! is_readable( $manifest ) ) {
            return array( 'status' => 'unverified', 'message' => 'No integrity manifest (legacy or imported archive).' );
        }
        $body = @file_get_contents( $manifest );
        $data = is_string( $body ) ? json_decode( $body, true ) : null;
        if ( is_array( $data ) && ! isset( $data['manifest_version'] ) ) {
            $feature_v1 = self::normalize_feature_v1_manifest( $data, $archive );
            if ( $feature_v1 !== null ) {
                $data = $feature_v1;
            }
        }
        if ( ! is_array( $data ) || ! isset( $data['manifest_version'], $data['algorithm'], $data['size_bytes'], $data['sha256'] ) ||
            ! in_array( $data['manifest_version'], array( self::VERSION, self::CHUNKED_VERSION ), true ) ||
            $data['algorithm'] !== 'sha256' ||
            ! is_int( $data['size_bytes'] ) || $data['size_bytes'] < 0 ||
            ! is_string( $data['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            return array( 'status' => 'invalid', 'message' => 'Invalid or unsupported manifest.' );
        }
        clearstatcache( true, $archive );
        $size = filesize( $archive );
        if ( $size === false || $size !== $data['size_bytes'] ) {
            return array( 'status' => 'corrupt', 'message' => 'Archive size does not match manifest.' );
        }
        if ( $data['manifest_version'] === self::CHUNKED_VERSION ) {
            try {
                $chunked = self::chunked_manifest( $archive );
            } catch ( Trasloco_Archive_Exception $e ) {
                return array( 'status' => 'invalid', 'message' => 'Invalid or unsupported chunk manifest.' );
            }
            $stream = @fopen( $archive, 'rb' );
            if ( $stream === false ) {
                return array( 'status' => 'missing', 'message' => 'Cannot read the archive.' );
            }
            // Hash all bytes once, accumulating whole-file and individual
            // chunk digests together. This avoids two full disk scans and
            // repeated file opens for multi-gigabyte archives.
            $full = hash_init( 'sha256' );
            $remaining = $chunked['size_bytes'];
            try {
                foreach ( $chunked['chunks'] as $expected ) {
                    $part_left = min( $remaining, self::CHUNK_BYTES );
                    $part_hash = hash_init( 'sha256' );
                    while ( $part_left > 0 ) {
                        $bytes = fread( $stream, min( $part_left, 1048576 ) );
                        if ( ! is_string( $bytes ) || $bytes === '' ) {
                            return array( 'status' => 'corrupt', 'message' => 'Archive ended during verification.' );
                        }
                        hash_update( $full, $bytes );
                        hash_update( $part_hash, $bytes );
                        $part_left -= strlen( $bytes );
                        $remaining -= strlen( $bytes );
                    }
                    if ( ! hash_equals( $expected, hash_final( $part_hash ) ) ) {
                        return array( 'status' => 'corrupt', 'message' => 'Archive chunk SHA-256 does not match manifest.' );
                    }
                }
                if ( $remaining !== 0 || fread( $stream, 1 ) !== '' ) {
                    return array( 'status' => 'corrupt', 'message' => 'Archive size changed during verification.' );
                }
                $hash = hash_final( $full );
            } finally {
                fclose( $stream );
            }
        } else {
            $hash = hash_file( 'sha256', $archive );
        }
        if ( ! is_string( $hash ) || ! hash_equals( $data['sha256'], $hash ) ) {
            return array( 'status' => 'corrupt', 'message' => 'Archive SHA-256 does not match manifest.' );
        }
        return array( 'status' => 'verified', 'message' => 'Archive size and SHA-256 match.' );
    }
}
