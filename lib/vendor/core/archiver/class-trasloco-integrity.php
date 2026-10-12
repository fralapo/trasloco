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

    public static function manifest_path( $archive ) {
        return $archive . '.manifest.json';
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
        if ( ! is_array( $data ) || ! isset( $data['manifest_version'], $data['algorithm'], $data['size_bytes'], $data['sha256'] ) ||
            $data['manifest_version'] !== self::VERSION || $data['algorithm'] !== 'sha256' ||
            ! is_int( $data['size_bytes'] ) || $data['size_bytes'] < 0 ||
            ! is_string( $data['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
            return array( 'status' => 'invalid', 'message' => 'Invalid or unsupported manifest.' );
        }
        clearstatcache( true, $archive );
        $size = filesize( $archive );
        if ( $size === false || $size !== $data['size_bytes'] ) {
            return array( 'status' => 'corrupt', 'message' => 'Archive size does not match manifest.' );
        }
        $hash = hash_file( 'sha256', $archive );
        if ( ! is_string( $hash ) || ! hash_equals( $data['sha256'], $hash ) ) {
            return array( 'status' => 'corrupt', 'message' => 'Archive SHA-256 does not match manifest.' );
        }
        return array( 'status' => 'verified', 'message' => 'Archive size and SHA-256 match.' );
    }
}
