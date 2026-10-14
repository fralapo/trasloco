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
        if ( ! rename( $temp, $target ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot publish encrypted archive.' );
        }
        return true;
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
        if ( ! rename( $temp, $target ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot publish decrypted archive.' );
        }
        return true;
    }
}
