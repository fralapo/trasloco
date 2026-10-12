<?php
/**
 * Password-based authenticated streaming encryption for offsite backups.
 * XChaCha20-Poly1305 secretstream + Argon2id (libsodium).
 * Format: TRLENC01 | 16-byte salt | 24-byte secretstream header |
 * repeated [uint32 big-endian ciphertext length | ciphertext], TAG_FINAL.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Crypt {
    const MAGIC = 'TRLENC01';
    const CHUNK = 1048576;

    private static function require_crypto( $password ) {
        if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) || ! function_exists( 'sodium_crypto_pwhash' ) ) {
            throw new RuntimeException( 'The PHP sodium extension is required.' );
        }
        if ( ! is_string( $password ) || strlen( $password ) < 12 ) {
            throw new RuntimeException( 'Passphrase must be at least 12 characters.' );
        }
    }

    private static function key( $password, $salt ) {
        return sodium_crypto_pwhash( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $password, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 );
    }

    private static function read( $stream, $count ) {
        $result = '';
        while ( strlen( $result ) < $count ) {
            $part = fread( $stream, $count - strlen( $result ) );
            if ( $part === false || $part === '' ) {
                throw new RuntimeException( 'Truncated encrypted archive.' );
            }
            $result .= $part;
        }
        return $result;
    }

    private static function write( $stream, $value ) {
        for ( $i = 0, $count = strlen( $value ); $i < $count; ) {
            $wrote = fwrite( $stream, substr( $value, $i ) );
            if ( $wrote === false || $wrote === 0 ) {
                throw new RuntimeException( 'Could not write encrypted backup.' );
            }
            $i += $wrote;
        }
    }

    private static function output( $path ) {
        if ( file_exists( $path ) || is_link( $path ) ) {
            throw new RuntimeException( 'Destination already exists.' );
        }
        $temp = $path . '.partial-' . bin2hex( random_bytes( 8 ) );
        $handle = @fopen( $temp, 'xb' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Could not open temporary output.' );
        }
        return array( $temp, $handle );
    }

    public static function encrypt_file( $source, $destination, $password ) {
        self::require_crypto( $password );
        if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source ) ) {
            throw new RuntimeException( 'Unreadable source archive.' );
        }
        $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
        $key = self::key( $password, $salt );
        list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
        sodium_memzero( $key );
        $input = @fopen( $source, 'rb' );
        if ( $input === false ) {
            throw new RuntimeException( 'Could not open source archive.' );
        }
        $output = null;
        $temp = null;
        try {
            list( $temp, $output ) = self::output( $destination );
            self::write( $output, self::MAGIC . $salt . $header );
            do {
                $plain = fread( $input, self::CHUNK );
                if ( $plain === false ) {
                    throw new RuntimeException( 'Failed reading the source archive.' );
                }
                $last = feof( $input );
                if ( $plain === '' && ! $last ) {
                    throw new RuntimeException( 'Unexpected empty archive read.' );
                }
                $tag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $plain, '', $tag );
                self::write( $output, pack( 'N', strlen( $cipher ) ) . $cipher );
            } while ( ! $last );
            if ( ! fflush( $output ) ) {
                throw new RuntimeException( 'Could not flush ciphertext.' );
            }
            fclose( $output );
            $output = null;
            if ( file_exists( $destination ) || ! @rename( $temp, $destination ) ) {
                throw new RuntimeException( 'Could not finalize ciphertext.' );
            }
        } finally {
            fclose( $input );
            if ( is_resource( $output ) ) { fclose( $output ); }
            if ( $temp !== null && is_file( $temp ) ) { @unlink( $temp ); }
        }
        return $destination;
    }

    public static function decrypt_file( $source, $destination, $password ) {
        self::require_crypto( $password );
        if ( ! is_file( $source ) || is_link( $source ) || ! is_readable( $source ) ) {
            throw new RuntimeException( 'Unreadable encrypted source.' );
        }
        $input = @fopen( $source, 'rb' );
        if ( $input === false ) {
            throw new RuntimeException( 'Could not open encrypted source.' );
        }
        $temp = null;
        $output = null;
        try {
            if ( self::read( $input, 8 ) !== self::MAGIC ) {
                throw new RuntimeException( 'Unsupported encrypted backup format.' );
            }
            $salt = self::read( $input, SODIUM_CRYPTO_PWHASH_SALTBYTES );
            $header = self::read( $input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
            $key = self::key( $password, $salt );
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
            sodium_memzero( $key );
            list( $temp, $output ) = self::output( $destination );
            do {
                $length = unpack( 'N', self::read( $input, 4 ) )[1];
                if ( $length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ||
                    $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ) {
                    throw new RuntimeException( 'Invalid ciphertext frame.' );
                }
                $chunk = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, self::read( $input, $length ) );
                if ( $chunk === false ) {
                    throw new RuntimeException( 'Authentication failed: incorrect passphrase or tampered archive.' );
                }
                self::write( $output, $chunk[0] );
                $final = $chunk[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            } while ( ! $final );
            if ( fread( $input, 1 ) !== '' ) {
                throw new RuntimeException( 'Trailing data after final authenticated chunk.' );
            }
            if ( ! fflush( $output ) ) {
                throw new RuntimeException( 'Could not flush plaintext.' );
            }
            fclose( $output );
            $output = null;
            if ( file_exists( $destination ) || ! @rename( $temp, $destination ) ) {
                throw new RuntimeException( 'Could not finalize decrypted file.' );
            }
        } finally {
            fclose( $input );
            if ( is_resource( $output ) ) { fclose( $output ); }
            if ( $temp !== null && is_file( $temp ) ) { @unlink( $temp ); }
        }
        return $destination;
    }
}
