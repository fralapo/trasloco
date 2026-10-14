<?php
/**
 * Opt-in authenticated streaming encryption for completed .wpress backups.
 * Requires libsodium. A v1 envelope is independent from the .wpress format.
 *
 * Layout: "TRASENC1" | 16-byte salt | 4-byte Argon ops |
 * 4-byte Argon mem | 24-byte secretstream header |
 * repeated (4-byte ciphertext length | authenticated ciphertext).
 */
class Trasloco_Encryption {
    const MAGIC = 'TRASENC1';
    const LEGACY_MAGIC = 'TRLENC01';
    const CHUNK = 1048576;

    private static function available() {
        if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) ||
            ! function_exists( 'sodium_crypto_pwhash' ) ) {
            throw new RuntimeException( 'The PHP sodium extension is required.' );
        }
    }

    private static function write( $handle, $bytes ) {
        $length = strlen( $bytes );
        $offset = 0;
        while ( $offset < $length ) {
            $n = fwrite( $handle, substr( $bytes, $offset ) );
            if ( $n === false || $n === 0 ) {
                throw new RuntimeException( 'Cannot write encrypted archive.' );
            }
            $offset += $n;
        }
    }

    private static function exact( $handle, $length ) {
        $result = '';
        while ( strlen( $result ) < $length ) {
            $next = fread( $handle, $length - strlen( $result ) );
            if ( $next === false || $next === '' ) {
                throw new RuntimeException( 'Truncated encrypted archive.' );
            }
            $result .= $next;
        }
        return $result;
    }

    private static function secret( $passphrase, $salt, $ops, $mem ) {
        if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
            throw new InvalidArgumentException( 'Use an archive passphrase of at least 12 characters.' );
        }
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            $ops,
            $mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    private static function allocate( $target ) {
        if ( file_exists( $target ) || is_link( $target ) ) {
            throw new RuntimeException( 'Destination archive already exists.' );
        }
        $temp = tempnam( dirname( $target ), '.trasloco-crypto-' );
        if ( $temp === false ) {
            throw new RuntimeException( 'Cannot allocate encrypted temporary file.' );
        }
        @chmod( $temp, 0600 );
        return $temp;
    }

    /** Publish without replacing a destination created after the initial check. */
    private static function publish_new_file( $temp, $target ) {
        if ( ! @link( $temp, $target ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot publish archive without replacing an existing file.' );
        }
        // Both paths name the same complete file; removing the private staging
        // link leaves the destination with the original 0600 permissions.
        @unlink( $temp );
        return true;
    }

    /**
     * Advance one bounded encrypt/decrypt batch while preserving the sodium
     * stream state in an authenticated, installation-key-sealed checkpoint.
     * The source and staging files must be in private Trasloco storage.
     */
    public static function resumable_step( $mode, $source, $temp, $passphrase, $checkpoint = null, $max_chunks = 4 ) {
        self::available();
        if ( ! in_array( $mode, array( 'encrypt', 'decrypt' ), true ) ||
            ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ||
            ! is_int( $max_chunks ) || $max_chunks < 1 || $max_chunks > 16 ) {
            throw new InvalidArgumentException( 'Invalid resumable encryption request.' );
        }
        $source_stat = self::resumable_file_stat( $source );
        if ( $checkpoint === null ) {
            if ( file_exists( $temp ) || is_link( $temp ) ) {
                if ( is_link( $temp ) || ! is_file( $temp ) || ! @unlink( $temp ) ) {
                    throw new RuntimeException( 'Cannot reset private encryption staging file.' );
                }
            }
            $state = self::resumable_begin( $mode, $source, $temp, $passphrase, $source_stat );
        } else {
            $state = self::validate_resumable_checkpoint( $checkpoint, $mode, $source_stat, $temp );
        }
        if ( ! empty( $state['complete'] ) ) {
            self::verify_resumable_passphrase( $state, $passphrase );
            return $state;
        }
        $sealed = self::verify_resumable_passphrase( $state, $passphrase );
        $in = @fopen( $source, 'rb' );
        $out = @fopen( $temp, 'c+b' );
        if ( ! $in || ! $out || is_link( $temp ) || fseek( $in, $state['input_offset'], SEEK_SET ) !== 0 ||
            fseek( $out, $state['output_offset'], SEEK_SET ) !== 0 || ! @ftruncate( $out, $state['output_offset'] ) ) {
            if ( $in ) { fclose( $in ); }
            if ( $out ) { fclose( $out ); }
            self::wipe_stream_state( $sealed );
            throw new RuntimeException( 'Cannot resume private encryption staging files.' );
        }
        @chmod( $temp, 0600 );
        $verifier = $sealed['verifier'];
        $stream = $sealed['stream'];
        $chunks = 0;
        $complete = false;
        try {
            if ( $mode === 'encrypt' ) {
                while ( $chunks < $max_chunks && $state['input_offset'] < $source_stat['size'] ) {
                    $needed = min( self::CHUNK, $source_stat['size'] - $state['input_offset'] );
                    $part = self::read_exact_or_eof( $in, $needed );
                    if ( strlen( $part ) !== $needed ) {
                        throw new RuntimeException( 'Source archive ended during encryption.' );
                    }
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $stream, $part );
                    self::write( $out, pack( 'N', strlen( $cipher ) ) . $cipher );
                    $state['input_offset'] += strlen( $part );
                    $state['output_offset'] += 4 + strlen( $cipher );
                    $chunks++;
                }
                if ( $state['input_offset'] === $source_stat['size'] ) {
                    $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                        $stream, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    );
                    self::write( $out, pack( 'N', strlen( $final ) ) . $final );
                    $state['output_offset'] += 4 + strlen( $final );
                    $complete = true;
                }
            } else {
                while ( $chunks < $max_chunks && ! $complete ) {
                    if ( fseek( $in, $state['input_offset'], SEEK_SET ) !== 0 ) {
                        throw new RuntimeException( 'Cannot seek encrypted archive.' );
                    }
                    $length_bytes = self::read_exact_or_eof( $in, 4 );
                    if ( strlen( $length_bytes ) !== 4 ) {
                        throw new RuntimeException( 'Encrypted archive has no final authenticated frame.' );
                    }
                    $frame_length = unpack( 'Nlength', $length_bytes )['length'];
                    if ( $frame_length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ||
                        $frame_length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ) {
                        throw new RuntimeException( 'Invalid encrypted archive frame.' );
                    }
                    $frame = self::read_exact_or_eof( $in, $frame_length );
                    if ( strlen( $frame ) !== $frame_length ) {
                        throw new RuntimeException( 'Truncated encrypted archive frame.' );
                    }
                    $plain = sodium_crypto_secretstream_xchacha20poly1305_pull( $stream, $frame );
                    if ( $plain === false ) {
                        throw new RuntimeException( 'Archive authentication failed (wrong password or altered data).' );
                    }
                    self::write( $out, $plain[0] );
                    $state['input_offset'] += 4 + $frame_length;
                    $state['output_offset'] += strlen( $plain[0] );
                    $complete = $plain[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                    if ( $complete && self::read_exact_or_eof( $in, 1 ) !== '' ) {
                        throw new RuntimeException( 'Trailing bytes after final encrypted frame.' );
                    }
                    $chunks++;
                }
            }
            if ( ! fflush( $out ) ) {
                throw new RuntimeException( 'Cannot flush resumable encryption staging file.' );
            }
            if ( function_exists( 'fsync' ) && ! @fsync( $out ) ) {
                throw new RuntimeException( 'Cannot synchronize resumable encryption staging file.' );
            }
        } catch ( Throwable $error ) {
            fclose( $in ); fclose( $out );
            sodium_memzero( $stream );
            sodium_memzero( $verifier );
            self::wipe_stream_state( $sealed );
            throw $error;
        }
        fclose( $in ); fclose( $out );
        clearstatcache( true, $source );
        if ( ! self::same_resumable_file( $source_stat, @lstat( $source ) ) ) {
            sodium_memzero( $stream );
            sodium_memzero( $verifier );
            self::wipe_stream_state( $sealed );
            throw new RuntimeException( 'Source archive changed during resumable encryption.' );
        }
        $state['sealed_state'] = self::seal_stream_state( array(
            'stream' => $stream,
            'verifier' => $verifier,
        ) );
        sodium_memzero( $stream );
        sodium_memzero( $verifier );
        self::wipe_stream_state( $sealed );
        $state['complete'] = $complete;
        $state['progress_bytes'] = $state['input_offset'];
        $state['total_bytes'] = $source_stat['size'];
        return $state;
    }

    private static function verify_resumable_passphrase( $state, $passphrase ) {
        $key = self::secret( $passphrase, base64_decode( $state['salt'], true ), $state['ops'], $state['mem'] );
        $sealed = self::unseal_stream_state( $state['sealed_state'] );
        $verifier = hash_hmac( 'sha256', 'trasloco-crypto-job-verifier', $key, true );
        sodium_memzero( $key );
        if ( ! isset( $sealed['stream'], $sealed['verifier'] ) || ! is_string( $sealed['stream'] ) ||
            ! is_string( $sealed['verifier'] ) || ! hash_equals( $sealed['verifier'], $verifier ) ) {
            sodium_memzero( $verifier );
            if ( isset( $sealed['stream'] ) && is_string( $sealed['stream'] ) ) { sodium_memzero( $sealed['stream'] ); }
            if ( isset( $sealed['verifier'] ) && is_string( $sealed['verifier'] ) ) { sodium_memzero( $sealed['verifier'] ); }
            throw new RuntimeException( 'Encryption configuration changed during the resumable operation.' );
        }
        sodium_memzero( $verifier );
        return $sealed;
    }

    private static function resumable_begin( $mode, $source, $temp, $passphrase, $source_stat ) {
        $input = @fopen( $source, 'rb' );
        $output = @fopen( $temp, 'x+b' );
        if ( ! $input || ! $output ) {
            if ( $input ) { fclose( $input ); }
            if ( $output ) { fclose( $output ); }
            @unlink( $temp );
            throw new RuntimeException( 'Cannot initialize private encryption staging files.' );
        }
        @chmod( $temp, 0600 );
        try {
            if ( $mode === 'encrypt' ) {
                $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
                $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
                $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
                $key = self::secret( $passphrase, $salt, $ops, $mem );
                list( $stream, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
                $encoded_header = self::MAGIC . $salt . pack( 'N2', $ops, $mem ) . $header;
                self::write( $output, $encoded_header );
                $input_offset = 0;
                $output_offset = strlen( $encoded_header );
            } else {
                $magic = self::read_exact_or_eof( $input, strlen( self::MAGIC ) );
                if ( $magic !== self::MAGIC && $magic !== self::LEGACY_MAGIC ) {
                    throw new RuntimeException( 'Unknown encrypted archive format.' );
                }
                $salt = self::read_exact_or_eof( $input, SODIUM_CRYPTO_PWHASH_SALTBYTES );
                if ( $magic === self::MAGIC ) {
                    $cost = unpack( 'Nops/Nmem', self::read_exact_or_eof( $input, 8 ) );
                    $ops = $cost['ops']; $mem = $cost['mem'];
                } else {
                    $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
                    $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
                }
                if ( $ops < 1 || $ops > SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE ||
                    $mem < 8 * 1024 * 1024 || $mem > SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE ) {
                    throw new RuntimeException( 'Unsupported archive key derivation parameters.' );
                }
                $header = self::read_exact_or_eof( $input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
                $key = self::secret( $passphrase, $salt, $ops, $mem );
                $stream = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
                if ( $stream === false ) {
                    sodium_memzero( $key );
                    throw new RuntimeException( 'Invalid encrypted archive header.' );
                }
                $encoded_header = $magic . $salt . ( $magic === self::MAGIC ? pack( 'N2', $ops, $mem ) : '' ) . $header;
                $input_offset = strlen( $encoded_header );
                $output_offset = 0;
            }
            $verifier = hash_hmac( 'sha256', 'trasloco-crypto-job-verifier', $key, true );
            sodium_memzero( $key );
            if ( ! fflush( $output ) ) { throw new RuntimeException( 'Cannot flush encryption header.' ); }
        } catch ( Throwable $error ) {
            if ( isset( $key ) && is_string( $key ) ) { sodium_memzero( $key ); }
            if ( isset( $stream ) && is_string( $stream ) ) { sodium_memzero( $stream ); }
            if ( isset( $verifier ) && is_string( $verifier ) ) { sodium_memzero( $verifier ); }
            fclose( $input ); fclose( $output ); @unlink( $temp ); throw $error;
        }
        fclose( $input ); fclose( $output );
        $sealed_state = self::seal_stream_state( array(
            'stream' => $stream,
            'verifier' => $verifier,
        ) );
        sodium_memzero( $stream );
        sodium_memzero( $verifier );
        return array(
            'mode' => $mode, 'source_size' => $source_stat['size'], 'source_mtime' => $source_stat['mtime'],
            'source_ctime' => $source_stat['ctime'], 'source_inode' => $source_stat['ino'], 'source_device' => $source_stat['dev'],
            'input_offset' => $input_offset, 'output_offset' => $output_offset,
            'salt' => base64_encode( $salt ), 'ops' => $ops, 'mem' => $mem,
            'header' => base64_encode( $mode === 'encrypt' ? $encoded_header : '' ),
            'sealed_state' => $sealed_state,
            'complete' => false,
        );
    }

    private static function validate_resumable_checkpoint( $state, $mode, $source_stat, $temp ) {
        $required = array( 'mode','source_size','source_mtime','source_ctime','source_inode','source_device',
            'input_offset','output_offset','salt','ops','mem','header','sealed_state','complete' );
        if ( ! is_array( $state ) || array_diff( $required, array_keys( $state ) ) || $state['mode'] !== $mode ||
            ! self::same_resumable_file( array(
                'size' => $state['source_size'], 'mtime' => $state['source_mtime'], 'ctime' => $state['source_ctime'],
                'ino' => $state['source_inode'], 'dev' => $state['source_device'],
            ), $source_stat ) || ! is_int( $state['input_offset'] ) || $state['input_offset'] < 0 ||
            $state['input_offset'] > $source_stat['size'] ||
            ! is_int( $state['output_offset'] ) || $state['output_offset'] < 0 ||
            ! is_string( $state['salt'] ) || ! is_string( $state['header'] ) || ! is_string( $state['sealed_state'] ) ||
            ! is_bool( $state['complete'] ) || ! isset( $state['ops'], $state['mem'] ) ||
            ! is_int( $state['ops'] ) || ! is_int( $state['mem'] ) ||
            $state['ops'] < 1 || $state['ops'] > SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE ||
            $state['mem'] < 8 * 1024 * 1024 || $state['mem'] > SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE ) {
            throw new RuntimeException( 'Resumable encryption checkpoint is invalid or its source changed.' );
        }
        $salt = base64_decode( $state['salt'], true );
        $header = base64_decode( $state['header'], true );
        if ( ! is_string( $salt ) || strlen( $salt ) !== SODIUM_CRYPTO_PWHASH_SALTBYTES ||
            ! is_string( $header ) || ( $mode === 'encrypt' && strlen( $header ) < 40 ) ||
            ( $mode === 'decrypt' && $header !== '' ) ) {
            throw new RuntimeException( 'Resumable encryption header is invalid.' );
        }
        clearstatcache( true, $temp );
        $temp_stat = @lstat( $temp );
        if ( ! is_array( $temp_stat ) || is_link( $temp ) || ! is_file( $temp ) ||
            (int) $temp_stat['size'] < $state['output_offset'] ) {
            throw new RuntimeException( 'Private encryption staging file is missing or truncated.' );
        }
        $output = @fopen( $temp, 'rb' );
        if ( ! $output ) { throw new RuntimeException( 'Cannot read private encryption staging header.' ); }
        $saved_header = self::read_exact_or_eof( $output, strlen( $header ) );
        fclose( $output );
        if ( ! hash_equals( $header, $saved_header ) ) {
            throw new RuntimeException( 'Private encryption staging header changed.' );
        }
        return $state;
    }

    private static function seal_stream_state( $state ) {
        $site_key = hash_hmac( 'sha256', 'trasloco-crypto-checkpoint-v1', wp_salt( 'auth' ), true );
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $body = serialize( $state );
        $sealed = sodium_crypto_secretbox( $body, $nonce, $site_key );
        sodium_memzero( $body );
        sodium_memzero( $site_key );
        return base64_encode( $nonce . $sealed );
    }

    /** Best-effort cleanup of sodium stream and derived verifier strings. */
    private static function wipe_stream_state( &$state ) {
        if ( ! is_array( $state ) ) { return; }
        foreach ( array( 'stream', 'verifier' ) as $field ) {
            if ( isset( $state[ $field ] ) && is_string( $state[ $field ] ) ) {
                sodium_memzero( $state[ $field ] );
            }
        }
    }

    private static function unseal_stream_state( $encoded ) {
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 4096 ) {
            throw new RuntimeException( 'Sealed encryption checkpoint is invalid.' );
        }
        $bytes = base64_decode( $encoded, true );
        if ( ! is_string( $bytes ) || strlen( $bytes ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
            throw new RuntimeException( 'Sealed encryption checkpoint is invalid.' );
        }
        $nonce = substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $site_key = hash_hmac( 'sha256', 'trasloco-crypto-checkpoint-v1', wp_salt( 'auth' ), true );
        $plain = sodium_crypto_secretbox_open( substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $site_key );
        sodium_memzero( $site_key );
        $state = is_string( $plain ) ? @unserialize( $plain, array( 'allowed_classes' => false ) ) : null;
        if ( is_string( $plain ) ) { sodium_memzero( $plain ); }
        if ( ! is_array( $state ) ) { throw new RuntimeException( 'Encryption checkpoint authentication failed.' ); }
        return $state;
    }

    private static function resumable_file_stat( $path ) {
        clearstatcache( true, $path );
        $stat = @lstat( $path );
        if ( ! is_array( $stat ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
            throw new RuntimeException( 'Resumable crypto source is missing, unsafe or unreadable.' );
        }
        return array( 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'ctime' => (int) $stat['ctime'],
            'ino' => (int) $stat['ino'], 'dev' => (int) $stat['dev'] );
    }

    private static function same_resumable_file( $expected, $actual ) {
        if ( ! is_array( $expected ) || ! is_array( $actual ) ) { return false; }
        foreach ( array( 'size','mtime','ctime','ino','dev' ) as $field ) {
            if ( ! isset( $expected[ $field ], $actual[ $field ] ) || (int) $expected[ $field ] !== (int) $actual[ $field ] ) { return false; }
        }
        return true;
    }

    private static function read_exact_or_eof( $handle, $length ) {
        $value = '';
        while ( strlen( $value ) < $length ) {
            $part = fread( $handle, $length - strlen( $value ) );
            if ( $part === false || $part === '' ) { break; }
            $value .= $part;
        }
        return $value;
    }

    public static function encrypt( $source, $target, $passphrase ) {
        self::available();
        if ( ! is_file( $source ) || is_link( $source ) ) {
            throw new RuntimeException( 'Source archive does not exist or is a symlink.' );
        }
        $temp = self::allocate( $target );
        $in = @fopen( $source, 'rb' );
        $out = @fopen( $temp, 'wb' );
        if ( ! $in || ! $out ) {
            if ( $in ) { fclose( $in ); }
            if ( $out ) { fclose( $out ); }
            @unlink( $temp );
            throw new RuntimeException( 'Cannot open archive for encryption.' );
        }
        try {
            $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
            $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
            $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
            $key = self::secret( $passphrase, $salt, $ops, $mem );
            list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
            sodium_memzero( $key );
            self::write( $out, self::MAGIC . $salt . pack( 'N2', $ops, $mem ) . $header );
            while ( ! feof( $in ) ) {
                $part = fread( $in, self::CHUNK );
                if ( $part === false ) {
                    throw new RuntimeException( 'Cannot read backup data.' );
                }
                if ( $part === '' ) {
                    break;
                }
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $part );
                self::write( $out, pack( 'N', strlen( $cipher ) ) . $cipher );
            }
            // Always write a final authenticated record, even on exact chunk boundaries.
            $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
            );
            self::write( $out, pack( 'N', strlen( $final ) ) . $final );
            if ( ! fflush( $out ) ) {
                throw new RuntimeException( 'Unable to flush encrypted archive.' );
            }
        } catch ( Throwable $e ) {
            fclose( $in );
            fclose( $out );
            @unlink( $temp );
            throw $e;
        }
        fclose( $in );
        fclose( $out );
        return self::publish_new_file( $temp, $target );
    }

    public static function decrypt( $source, $target, $passphrase ) {
        self::available();
        if ( ! is_file( $source ) || is_link( $source ) ) {
            throw new RuntimeException( 'Encrypted archive is missing or is a symlink.' );
        }
        $temp = self::allocate( $target );
        $in = @fopen( $source, 'rb' );
        $out = @fopen( $temp, 'wb' );
        if ( ! $in || ! $out ) {
            if ( $in ) { fclose( $in ); }
            if ( $out ) { fclose( $out ); }
            @unlink( $temp );
            throw new RuntimeException( 'Cannot open encrypted archive.' );
        }
        try {
            $magic = self::exact( $in, strlen( self::MAGIC ) );
            if ( $magic === self::MAGIC ) {
                $salt = self::exact( $in, SODIUM_CRYPTO_PWHASH_SALTBYTES );
                $cost = unpack( 'Nops/Nmem', self::exact( $in, 8 ) );
                $ops = $cost['ops'];
                $mem = $cost['mem'];
            } elseif ( $magic === self::LEGACY_MAGIC ) {
                // The feature branch used fixed interactive Argon2id costs
                // and omitted them from the envelope header.
                $salt = self::exact( $in, SODIUM_CRYPTO_PWHASH_SALTBYTES );
                $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
                $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
            } else {
                throw new RuntimeException( 'Unknown encrypted archive format.' );
            }
            // Prevent malicious envelopes from requesting unbounded CPU/RAM.
            if ( $ops < 1 || $ops > SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE ||
                $mem < 8 * 1024 * 1024 || $mem > SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE ) {
                throw new RuntimeException( 'Unsupported archive key derivation parameters.' );
            }
            $header = self::exact( $in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
            $key = self::secret( $passphrase, $salt, $ops, $mem );
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
            sodium_memzero( $key );
            if ( $state === false ) {
                throw new RuntimeException( 'Invalid encrypted archive header.' );
            }
            do {
                $frame_length = unpack( 'Nlength', self::exact( $in, 4 ) )['length'];
                if ( $frame_length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ||
                    $frame_length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ) {
                    throw new RuntimeException( 'Invalid encrypted archive frame.' );
                }
                $packet = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, self::exact( $in, $frame_length ) );
                if ( $packet === false ) {
                    throw new RuntimeException( 'Archive authentication failed (wrong password or altered data).' );
                }
                self::write( $out, $packet[0] );
                $final = $packet[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            } while ( ! $final );
            if ( fread( $in, 1 ) !== '' || ! fflush( $out ) ) {
                throw new RuntimeException( 'Trailing bytes or incomplete output.' );
            }
        } catch ( Throwable $e ) {
            fclose( $in );
            fclose( $out );
            @unlink( $temp );
            throw $e;
        }
        fclose( $in );
        fclose( $out );
        return self::publish_new_file( $temp, $target );
    }
}
