<?php
/**
 * Authenticated, resumable site-to-site pull protocol.
 *
 * WordPress Application Passwords are read from the process environment.
 * Partial archives stay in plugin-private storage until SHA-256 verification.
 * No remote source can trigger an automatic restore.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Peer {
    const CHUNK = 524288;

    private static function endpoint( $base, $archive, $suffix ) {
        return rtrim( $base, '/' ) . '/wp-json/trasloco/v1/backups/' . rawurlencode( $archive ) . '/' . $suffix;
    }

    private static function request( $url, $auth ) {
        $result = wp_safe_remote_get( $url, array(
            'timeout' => 40,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . $auth,
            ),
        ) );
        if ( is_wp_error( $result ) || wp_remote_retrieve_response_code( $result ) !== 200 ) {
            throw new RuntimeException( 'Remote backup transfer failed or was denied.' );
        }
        $body = wp_remote_retrieve_body( $result );
        if ( ! is_string( $body ) || strlen( $body ) > 960000 ) {
            throw new RuntimeException( 'Remote backup response is invalid or oversized.' );
        }
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            throw new RuntimeException( 'Remote backup response is not valid JSON.' );
        }
        return $data;
    }

    private static function write_all( $stream, $bytes ) {
        $total = strlen( $bytes );
        for ( $offset = 0; $offset < $total; ) {
            $count = fwrite( $stream, substr( $bytes, $offset ) );
            if ( $count === false || $count === 0 ) {
                throw new RuntimeException( 'Failed writing the downloaded archive.' );
            }
            $offset += $count;
        }
    }

    public static function pull( $base, $archive, $username, $password ) {
        Trasloco_Storage::validate_object( $archive );
        if ( substr( $archive, -7 ) !== '.wpress' ) {
            throw new RuntimeException( 'Direct transfer currently supports full .wpress archives only.' );
        }
        if ( ! is_string( $base ) || parse_url( $base, PHP_URL_SCHEME ) !== 'https' ||
             ! wp_http_validate_url( $base ) ) {
            throw new RuntimeException( 'Peer URL must be an externally reachable HTTPS site.' );
        }
        if ( ! is_string( $username ) || $username === '' || ! is_string( $password ) || $password === '' ) {
            throw new RuntimeException( 'Provide a dedicated WordPress Application Password in the environment.' );
        }
        if ( ! is_dir( TRASLOCO_BACKUPS_PATH ) && ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
            throw new RuntimeException( 'Cannot create local backup directory.' );
        }
        $destination = Trasloco_Manifest::archive_path( $archive );
        if ( is_file( $destination ) || is_link( $destination ) ) {
            throw new RuntimeException( 'Backup already exists locally: refusing to overwrite it.' );
        }
        $auth = base64_encode( $username . ':' . $password );
        $remote = self::request( self::endpoint( $base, $archive, 'manifest' ), $auth );
        if ( ! isset( $remote['bytes'], $remote['sha256'], $remote['archive'] ) ||
             ! is_int( $remote['bytes'] ) || $remote['bytes'] <= 0 ||
             ! is_string( $remote['sha256'] ) ||
             ! preg_match( '/^[a-f0-9]{64}$/D', $remote['sha256'] ) || $remote['archive'] !== $archive ) {
            throw new RuntimeException( 'Remote site did not supply a verified backup manifest.' );
        }
        $identity = hash( 'sha256', rtrim( $base, '/' ) . '|' . $username . '|' . $archive );
        $folder = TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . 'peer-' . $identity;
        if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
            throw new RuntimeException( 'Cannot create private transfer directory.' );
        }
        $payload = $folder . DIRECTORY_SEPARATOR . 'download.part';
        $metadata = $folder . DIRECTORY_SEPARATOR . 'transfer.json';
        $expected = array( 'bytes' => $remote['bytes'], 'sha256' => $remote['sha256'], 'archive' => $archive );
        if ( is_link( $payload ) || is_link( $metadata ) ) {
            throw new RuntimeException( 'Unsafe partial transfer files.' );
        }
        if ( is_file( $metadata ) ) {
            $prior = json_decode( (string) file_get_contents( $metadata ), true );
            if ( ! is_array( $prior ) || $prior !== $expected ) {
                throw new RuntimeException( 'Remote backup changed: existing transfer cannot safely resume.' );
            }
        } elseif ( ! file_exists( $payload ) ) {
            if ( file_put_contents( $metadata, json_encode( $expected ), LOCK_EX ) === false ) {
                throw new RuntimeException( 'Unable to save private transfer metadata.' );
            }
        } else {
            throw new RuntimeException( 'Partial archive exists without its transfer manifest.' );
        }

        $stream = @fopen( $payload, 'c+b' );
        if ( $stream === false ) {
            throw new RuntimeException( 'Cannot open partial archive.' );
        }
        if ( ! flock( $stream, LOCK_EX | LOCK_NB ) ) {
            fclose( $stream );
            throw new RuntimeException( 'Another worker is already downloading this backup.' );
        }
        try {
            $stat = fstat( $stream );
            $offset = $stat['size'];
            if ( $offset < 0 || $offset > $remote['bytes'] ) {
                throw new RuntimeException( 'Partial archive length exceeds manifest.' );
            }
            while ( $offset < $remote['bytes'] ) {
                $chunk = self::request( self::endpoint( $base, $archive, 'chunk' ) . '?offset=' . $offset, $auth );
                if ( ! isset( $chunk['offset'], $chunk['length'], $chunk['sha256'], $chunk['data'] ) ||
                     ! is_int( $chunk['offset'] ) || $chunk['offset'] !== $offset ||
                     ! is_int( $chunk['length'] ) || $chunk['length'] < 1 || $chunk['length'] > self::CHUNK ||
                     ! is_string( $chunk['data'] ) || ! is_string( $chunk['sha256'] ) ) {
                    throw new RuntimeException( 'Invalid remote transfer frame.' );
                }
                $bytes = base64_decode( $chunk['data'], true );
                if ( $bytes === false || strlen( $bytes ) !== $chunk['length'] ||
                     ! hash_equals( hash( 'sha256', $bytes ), $chunk['sha256'] ) ||
                     $offset + strlen( $bytes ) > $remote['bytes'] ) {
                    throw new RuntimeException( 'Remote frame failed integrity verification.' );
                }
                if ( fseek( $stream, $offset, SEEK_SET ) !== 0 ) {
                    throw new RuntimeException( 'Could not seek partial archive.' );
                }
                self::write_all( $stream, $bytes );
                if ( ! fflush( $stream ) ) {
                    throw new RuntimeException( 'Could not flush partial archive.' );
                }
                $offset += strlen( $bytes );
            }
            if ( ! hash_equals( $remote['sha256'], hash_file( 'sha256', $payload ) ) ) {
                throw new RuntimeException( 'Complete peer download failed SHA-256 verification.' );
            }
        } finally {
            flock( $stream, LOCK_UN );
            fclose( $stream );
        }
        if ( file_exists( $destination ) || is_link( $destination ) || ! @rename( $payload, $destination ) ) {
            throw new RuntimeException( 'Could not safely finalize peer transfer.' );
        }
        $manifest = Trasloco_Manifest::create( $archive );
        if ( ! hash_equals( $remote['sha256'], $manifest['sha256'] ) ) {
            @unlink( $destination );
            @unlink( Trasloco_Manifest::manifest_path( $archive ) );
            throw new RuntimeException( 'Final archive checksum did not match remote manifest.' );
        }
        @unlink( $metadata );
        @rmdir( $folder );
        return $destination;
    }
}
