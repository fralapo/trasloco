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
    const SHA256_K = array(
        0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
        0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
        0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
        0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
        0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
        0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
        0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
        0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
    );

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

    /**
     * Advance a bounded chunk-manifest scan. A portable SHA-256 compression
     * state is authenticated in the private job checkpoint; chunk digests live
     * in an append-only journal so the checkpoint stays constant-size.
     */
    public static function create_chunked_step( $archive, $job_key, $checkpoint = null, $max_chunks = 8 ) {
        if ( ! is_string( $job_key ) || ! preg_match( '/^[A-Za-z0-9-]{1,64}$/D', $job_key ) ||
            ! is_int( $max_chunks ) || $max_chunks < 1 || $max_chunks > 64 ||
            ! is_file( $archive ) || ! is_readable( $archive ) || is_link( $archive ) ) {
            throw new Trasloco_Archive_Exception( 'Archive cannot be scanned for a resumable checksum manifest.' );
        }
        clearstatcache( true, $archive );
        $archive_stat = @lstat( $archive );
        if ( ! is_array( $archive_stat ) ) { throw new Trasloco_Archive_Exception( 'Archive cannot be inspected.' ); }
        $identity = array( 'size' => (int) $archive_stat['size'], 'mtime' => (int) $archive_stat['mtime'],
            'ctime' => (int) $archive_stat['ctime'], 'ino' => (int) $archive_stat['ino'], 'dev' => (int) $archive_stat['dev'] );
        $journal = $archive . '.trasloco-manifest-' . $job_key . '.parts';
        if ( $checkpoint === null ) {
            if ( file_exists( $journal ) || is_link( $journal ) ) {
                throw new Trasloco_Archive_Exception( 'A resumable manifest journal already exists.' );
            }
            $journal_handle = @fopen( $journal, 'x+b' );
            if ( $journal_handle === false ) { throw new Trasloco_Archive_Exception( 'Cannot create a private manifest journal.' ); }
            @chmod( $journal, 0600 );
            $journal_stat = @lstat( $journal );
            $state = array( 'identity' => $identity, 'offset' => 0, 'next_chunk' => 0,
                'whole_state' => self::sha256_initial_state(),
                'journal_bytes' => 0, 'journal_inode' => (int) $journal_stat['ino'],
                'journal_device' => (int) $journal_stat['dev'] );
            $state['whole_state_mac'] = self::sha256_checkpoint_mac( $state );
            fclose( $journal_handle );
            return array( 'complete' => false, 'chunks_processed' => 0, 'checkpoint' => $state );
        } else {
            $required = array( 'identity', 'offset', 'next_chunk', 'whole_state', 'journal_bytes', 'journal_inode', 'journal_device' );
            if ( ! is_array( $checkpoint ) || array_diff( $required, array_keys( $checkpoint ) ) ||
                $checkpoint['identity'] !== $identity || ! is_int( $checkpoint['offset'] ) ||
                $checkpoint['offset'] < 0 || $checkpoint['offset'] > $identity['size'] ||
                ! is_int( $checkpoint['next_chunk'] ) || $checkpoint['next_chunk'] < 0 ||
                $checkpoint['next_chunk'] !== (int) ceil( $checkpoint['offset'] / self::CHUNK_BYTES ) ||
                ! is_int( $checkpoint['journal_bytes'] ) ||
                $checkpoint['journal_bytes'] !== $checkpoint['next_chunk'] * 64 ||
                ! is_int( $checkpoint['journal_inode'] ) || ! is_int( $checkpoint['journal_device'] ) ) {
                throw new Trasloco_Archive_Exception( 'Archive or manifest checkpoint changed.' );
            }
            if ( ! self::valid_sha256_state( $checkpoint['whole_state'], $checkpoint['offset'] ) ) {
                throw new Trasloco_Archive_Exception( 'Resumable manifest digest checkpoint is invalid.' );
            }
            $journal_stat = @lstat( $journal );
            if ( ! is_array( $journal_stat ) || is_link( $journal ) || ! is_file( $journal ) ||
                (int) $journal_stat['nlink'] !== 1 || (int) $journal_stat['ino'] !== $checkpoint['journal_inode'] ||
                (int) $journal_stat['dev'] !== $checkpoint['journal_device'] ||
                (int) $journal_stat['size'] < $checkpoint['journal_bytes'] ) {
                throw new Trasloco_Archive_Exception( 'Private manifest journal is missing or changed.' );
            }
            if ( isset( $checkpoint['whole_state_mac'] ) ) {
                if ( ! is_string( $checkpoint['whole_state_mac'] ) ||
                    ! hash_equals( self::sha256_checkpoint_mac( $checkpoint ), $checkpoint['whole_state_mac'] ) ) {
                    throw new Trasloco_Archive_Exception( 'Resumable manifest digest checkpoint authentication failed.' );
                }
            } else {
                // Upgrade checkpoints created before state authentication was added.
                // This one-time prefix replay binds the supplied state to both the
                // archive bytes and the already committed chunk-digest journal.
                if ( ! self::verify_legacy_sha256_checkpoint( $archive, $journal, $checkpoint ) ) {
                    throw new Trasloco_Archive_Exception( 'Legacy resumable manifest digest checkpoint cannot be verified.' );
                }
                $checkpoint['whole_state_mac'] = self::sha256_checkpoint_mac( $checkpoint );
            }
            $state = $checkpoint;
            $state['_whole_state'] = $checkpoint['whole_state'];
            $journal_handle = @fopen( $journal, 'c+b' );
            if ( $journal_handle === false || ! @ftruncate( $journal_handle, $state['journal_bytes'] ) ||
                fseek( $journal_handle, $state['journal_bytes'], SEEK_SET ) !== 0 ) {
                if ( is_resource( $journal_handle ) ) { fclose( $journal_handle ); }
                throw new Trasloco_Archive_Exception( 'Cannot resume the private manifest journal.' );
            }
        }
        if ( ! isset( $state['_whole_state'] ) ) { $state['_whole_state'] = $state['whole_state']; }
        $input = @fopen( $archive, 'rb' );
        if ( $input === false || fseek( $input, $state['offset'], SEEK_SET ) !== 0 ) {
            if ( $input ) { fclose( $input ); }
            fclose( $journal_handle );
            throw new Trasloco_Archive_Exception( 'Cannot resume archive checksum scan.' );
        }
        $processed = 0;
        try {
            while ( $state['offset'] < $identity['size'] && $processed < $max_chunks ) {
                $needed = min( self::CHUNK_BYTES, $identity['size'] - $state['offset'] );
                $chunk_hash = hash_init( 'sha256' );
                $chunk_bytes = 0;
                $whole_part = '';
                while ( $chunk_bytes < $needed ) {
                    $bytes = fread( $input, min( 1048576, $needed - $chunk_bytes ) );
                    if ( ! is_string( $bytes ) || $bytes === '' ) {
                        throw new Trasloco_Archive_Exception( 'Archive changed during resumable checksum creation.' );
                    }
                    $whole_part .= $bytes;
                    hash_update( $chunk_hash, $bytes );
                    $chunk_bytes += strlen( $bytes );
                }
                $state['_whole_state'] = self::sha256_update_state( $state['_whole_state'], $whole_part );
                $digest = hash_final( $chunk_hash );
                if ( strlen( $digest ) !== 64 ) {
                    throw new Trasloco_Archive_Exception( 'Cannot append a chunk checksum to the private journal.' );
                }
                $written = 0;
                while ( $written < 64 ) {
                    $count = fwrite( $journal_handle, substr( $digest, $written ) );
                    if ( $count === false || $count === 0 ) {
                        throw new Trasloco_Archive_Exception( 'Cannot append a chunk checksum to the private journal.' );
                    }
                    $written += $count;
                }
                $state['offset'] += $chunk_bytes;
                $state['next_chunk']++;
                $state['journal_bytes'] += 64;
                $processed++;
            }
            if ( ! fflush( $journal_handle ) || ( function_exists( 'fsync' ) && ! @fsync( $journal_handle ) ) ) {
                throw new Trasloco_Archive_Exception( 'Cannot synchronize the private manifest journal.' );
            }
        } catch ( Throwable $error ) {
            fclose( $input ); fclose( $journal_handle );
            throw $error;
        }
        fclose( $input ); fclose( $journal_handle );
        clearstatcache( true, $archive );
        $latest = @lstat( $archive );
        if ( ! is_array( $latest ) || $identity !== array( 'size' => (int) $latest['size'],
                'mtime' => (int) $latest['mtime'], 'ctime' => (int) $latest['ctime'],
                'ino' => (int) $latest['ino'], 'dev' => (int) $latest['dev'] ) ) {
            throw new Trasloco_Archive_Exception( 'Archive changed during resumable checksum creation.' );
        }
        if ( $state['offset'] < $identity['size'] ) {
            $state['whole_state'] = $state['_whole_state'];
            unset( $state['_whole_state'] );
            $state['whole_state_mac'] = self::sha256_checkpoint_mac( $state );
            return array( 'complete' => false, 'chunks_processed' => $processed, 'checkpoint' => $state );
        }
        if ( $state['journal_bytes'] !== (int) ceil( $identity['size'] / self::CHUNK_BYTES ) * 64 ) {
            throw new Trasloco_Archive_Exception( 'Private manifest journal has an invalid chunk count.' );
        }
        $whole_digest = self::sha256_final_state( $state['_whole_state'] );
        $journal_handle = @fopen( $journal, 'rb' );
        if ( $journal_handle === false ) { throw new Trasloco_Archive_Exception( 'Cannot read the private manifest journal.' ); }
        $chunks = array();
        try {
            while ( ! feof( $journal_handle ) ) {
                $digest = fread( $journal_handle, 64 );
                if ( $digest === '' ) { break; }
                if ( strlen( $digest ) !== 64 || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
                    throw new Trasloco_Archive_Exception( 'Private manifest journal contains an invalid digest.' );
                }
                $chunks[] = $digest;
            }
        } finally { fclose( $journal_handle ); }
        $manifest = array( 'manifest_version' => self::CHUNKED_VERSION, 'algorithm' => 'sha256',
            'size_bytes' => $identity['size'], 'sha256' => $whole_digest,
            'chunk_bytes' => self::CHUNK_BYTES, 'chunks' => $chunks );
        $encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_MANIFEST_BYTES ) {
            throw new Trasloco_Archive_Exception( 'Cannot encode chunk manifest within allowed limits.' );
        }
        $manifest_path = self::atomic_publish( $archive, $encoded );
        return array( 'complete' => true, 'chunks_processed' => $processed, 'manifest' => $manifest_path );
    }

    /** Portable SHA-256 state for JSON checkpoints on PHP 7.4 through current PHP. */
    private static function sha256_initial_state() {
        return array( 'words' => array( 0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,
            0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19 ), 'buffer' => '', 'bytes' => 0 );
    }

    /** Authenticate resumable digest state against the installation secret. */
    private static function sha256_checkpoint_mac( $checkpoint ) {
        if ( ! function_exists( 'wp_salt' ) ) {
            throw new Trasloco_Archive_Exception( 'WordPress authentication salt is unavailable for the manifest checkpoint.' );
        }
        $fields = array( 'identity', 'offset', 'next_chunk', 'whole_state', 'journal_bytes', 'journal_inode', 'journal_device' );
        $payload = array();
        foreach ( $fields as $field ) {
            if ( ! array_key_exists( $field, $checkpoint ) ) {
                throw new Trasloco_Archive_Exception( 'Resumable manifest digest checkpoint is incomplete.' );
            }
            $payload[ $field ] = $checkpoint[ $field ];
        }
        $encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
        $secret = wp_salt( 'auth' );
        if ( ! is_string( $encoded ) || ! is_string( $secret ) || strlen( $secret ) < 32 ) {
            throw new Trasloco_Archive_Exception( 'WordPress authentication salt is invalid for the manifest checkpoint.' );
        }
        return hash_hmac( 'sha256', $encoded, $secret );
    }

    /** Verify an older unauthenticated checkpoint by replaying its committed prefix once. */
    private static function verify_legacy_sha256_checkpoint( $archive, $journal, $checkpoint ) {
        $input = @fopen( $archive, 'rb' );
        $digests = @fopen( $journal, 'rb' );
        if ( $input === false || $digests === false ) {
            if ( is_resource( $input ) ) { fclose( $input ); }
            if ( is_resource( $digests ) ) { fclose( $digests ); }
            return false;
        }
        $state = self::sha256_initial_state();
        $remaining = $checkpoint['offset'];
        try {
            while ( $remaining > 0 ) {
                $chunk_length = min( self::CHUNK_BYTES, $remaining );
                $chunk = '';
                while ( strlen( $chunk ) < $chunk_length ) {
                    $part = fread( $input, min( 1048576, $chunk_length - strlen( $chunk ) ) );
                    if ( ! is_string( $part ) || $part === '' ) { return false; }
                    $chunk .= $part;
                }
                $expected = fread( $digests, 64 );
                if ( ! is_string( $expected ) || strlen( $expected ) !== 64 ||
                    ! hash_equals( $expected, hash( 'sha256', $chunk ) ) ) { return false; }
                $state = self::sha256_update_state( $state, $chunk );
                $remaining -= $chunk_length;
            }
            return $state === $checkpoint['whole_state'];
        } finally {
            fclose( $input );
            fclose( $digests );
        }
    }

    private static function valid_sha256_state( $state, $offset ) {
        if ( ! is_array( $state ) || count( $state ) !== 3 ||
            ! isset( $state['words'], $state['buffer'], $state['bytes'] ) ||
            ! is_array( $state['words'] ) || array_keys( $state['words'] ) !== range( 0, 7 ) ||
            ! is_string( $state['buffer'] ) || ! is_int( $state['bytes'] ) || $state['bytes'] !== $offset ) {
            return false;
        }
        foreach ( $state['words'] as $word ) {
            if ( ! is_int( $word ) || $word < 0 || $word > 0xffffffff ) { return false; }
        }
        $buffer = base64_decode( $state['buffer'], true );
        return is_string( $buffer ) && strlen( $buffer ) < 64 && strlen( $buffer ) === $offset % 64;
    }

    private static function sha256_update_state( $state, $bytes ) {
        $buffer = base64_decode( $state['buffer'], true );
        if ( ! is_string( $buffer ) ) { throw new Trasloco_Archive_Exception( 'Invalid SHA-256 buffer checkpoint.' ); }
        $data = $buffer . $bytes;
        $length = strlen( $data );
        $complete = $length - ( $length % 64 );
        for ( $offset = 0; $offset < $complete; $offset += 64 ) {
            $state['words'] = self::sha256_compress( $state['words'], substr( $data, $offset, 64 ) );
        }
        return array( 'words' => $state['words'], 'buffer' => base64_encode( substr( $data, $complete ) ),
            'bytes' => $state['bytes'] + strlen( $bytes ) );
    }

    private static function sha256_final_state( $state ) {
        $buffer = base64_decode( $state['buffer'], true );
        if ( ! is_string( $buffer ) || ! self::valid_sha256_state( $state, $state['bytes'] ) ) {
            throw new Trasloco_Archive_Exception( 'Invalid SHA-256 final checkpoint.' );
        }
        $padding_bytes = ( 56 - ( ( $state['bytes'] + 1 ) % 64 ) + 64 ) % 64;
        $bit_length_high = ( $state['bytes'] >> 29 ) & 0xffffffff;
        $bit_length_low = ( $state['bytes'] << 3 ) & 0xffffffff;
        $final = $buffer . "\x80" . str_repeat( "\0", $padding_bytes ) . pack( 'N2', $bit_length_high, $bit_length_low );
        for ( $offset = 0, $length = strlen( $final ); $offset < $length; $offset += 64 ) {
            $state['words'] = self::sha256_compress( $state['words'], substr( $final, $offset, 64 ) );
        }
        $digest = '';
        foreach ( $state['words'] as $word ) { $digest .= sprintf( '%08x', $word ); }
        return $digest;
    }

    private static function sha256_compress( $state, $block ) {
        $unpacked = unpack( 'N16', $block );
        $words = array_values( $unpacked );
        for ( $index = 16; $index < 64; $index++ ) {
            $x = $words[ $index - 15 ]; $y = $words[ $index - 2 ];
            $small_zero = self::sha256_rotr( $x, 7 ) ^ self::sha256_rotr( $x, 18 ) ^ ( $x >> 3 );
            $small_one = self::sha256_rotr( $y, 17 ) ^ self::sha256_rotr( $y, 19 ) ^ ( $y >> 10 );
            $words[ $index ] = self::sha256_add32( $words[ $index - 16 ], $small_zero,
                $words[ $index - 7 ], $small_one );
        }
        list( $a, $b, $c, $d, $e, $f, $g, $h ) = $state;
        for ( $index = 0; $index < 64; $index++ ) {
            $big_one = self::sha256_rotr( $e, 6 ) ^ self::sha256_rotr( $e, 11 ) ^ self::sha256_rotr( $e, 25 );
            $choose = ( $e & $f ) ^ ( ( ~ $e ) & $g );
            $temp_one = self::sha256_add32( $h, $big_one, $choose, self::SHA256_K[ $index ], $words[ $index ] );
            $big_zero = self::sha256_rotr( $a, 2 ) ^ self::sha256_rotr( $a, 13 ) ^ self::sha256_rotr( $a, 22 );
            $majority = ( $a & $b ) ^ ( $a & $c ) ^ ( $b & $c );
            $temp_two = self::sha256_add32( $big_zero, $majority );
            $h = $g; $g = $f; $f = $e; $e = self::sha256_add32( $d, $temp_one );
            $d = $c; $c = $b; $b = $a; $a = self::sha256_add32( $temp_one, $temp_two );
        }
        return array(
            self::sha256_add32( $state[0], $a ), self::sha256_add32( $state[1], $b ),
            self::sha256_add32( $state[2], $c ), self::sha256_add32( $state[3], $d ),
            self::sha256_add32( $state[4], $e ), self::sha256_add32( $state[5], $f ),
            self::sha256_add32( $state[6], $g ), self::sha256_add32( $state[7], $h ),
        );
    }

    private static function sha256_add32( $a, $b, $c = 0, $d = 0, $e = 0 ) {
        return ( $a + $b + $c + $d + $e ) & 0xffffffff;
    }

    private static function sha256_rotr( $word, $bits ) {
        return ( ( $word >> $bits ) | ( ( $word << ( 32 - $bits ) ) & 0xffffffff ) ) & 0xffffffff;
    }

    /** Remove only the private journal owned by one completed/abandoned job. */
    public static function cleanup_chunked_journal( $archive, $job_key ) {
        if ( ! is_string( $job_key ) || ! preg_match( '/^[A-Za-z0-9-]{1,64}$/D', $job_key ) ) { return false; }
        $journal = $archive . '.trasloco-manifest-' . $job_key . '.parts';
        if ( ! file_exists( $journal ) && ! is_link( $journal ) ) { return true; }
        if ( is_link( $journal ) || ! is_file( $journal ) ) { return false; }
        return @unlink( $journal );
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
        return array(
            'status' => 'verified',
            'message' => 'Archive size and SHA-256 match.',
            'size_bytes' => $size,
            'sha256' => $hash,
        );
    }
}
