<?php
/**
 * Optional integrity sidecar for full .wpress backups.
 *
 * SHA-256 detects accidental corruption; it does not authenticate the sender.
 * Never read arbitrary paths, follow symlinks, or accept unbounded JSON input.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Manifest {
    const SUFFIX = '.manifest.json';
    const MAX_BYTES = 4096;

    public static function archive_path( $name ) {
        if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_.-]+\\.wpress$/D', $name ) ) {
            throw new Trasloco_Archive_Exception( 'Invalid archive name.' );
        }
        $path = trasloco_backup_path( array( 'archive' => $name ) );
        if ( is_link( $path ) || is_link( $path . self::SUFFIX ) ) {
            throw new Trasloco_Archive_Exception( 'Symbolic links are not allowed for backup manifests.' );
        }
        return $path;
    }

    public static function manifest_path( $name ) {
        return self::archive_path( $name ) . self::SUFFIX;
    }

    public static function create( $name ) {
        $archive = self::archive_path( $name );
        if ( ! is_file( $archive ) || ! is_readable( $archive ) ) {
            throw new Trasloco_Archive_Exception( 'Backup file not readable.' );
        }
        clearstatcache( true, $archive );
        $bytes = filesize( $archive );
        $digest = hash_file( 'sha256', $archive );
        if ( $bytes === false || $digest === false ) {
            throw new Trasloco_Archive_Exception( 'Could not calculate backup checksum.' );
        }
        $manifest = array(
            'schema' => 1,
            'format' => 'wpress',
            'archive' => $name,
            'bytes' => $bytes,
            'sha256' => $digest,
            'created_at' => gmdate( 'c' ),
        );
        $json = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        $target = self::manifest_path( $name );
        $temp = $target . '.tmp-' . bin2hex( random_bytes( 8 ) );
        if ( @file_put_contents( $temp, $json, LOCK_EX ) !== strlen( $json ) || ! @rename( $temp, $target ) ) {
            @unlink( $temp );
            throw new Trasloco_Archive_Exception( 'Unable to write backup integrity manifest.' );
        }
        return $manifest;
    }

    public static function inspect( $name, $verify_contents = true ) {
        $path = self::archive_path( $name );
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return array( 'status' => 'missing', 'reason' => 'Archive is missing.' );
        }
        $sidecar = self::manifest_path( $name );
        if ( ! is_file( $sidecar ) ) {
            return array( 'status' => 'legacy', 'reason' => 'No integrity manifest: export predates this feature.' );
        }
        if ( is_link( $sidecar ) || filesize( $sidecar ) > self::MAX_BYTES ) {
            return array( 'status' => 'invalid', 'reason' => 'Unsafe or oversized integrity manifest.' );
        }
        $manifest = json_decode( (string) @file_get_contents( $sidecar ), true );
        if ( ! is_array( $manifest ) ||
            ! isset( $manifest['schema'], $manifest['archive'], $manifest['format'], $manifest['bytes'], $manifest['sha256'] ) ||
            $manifest['schema'] !== 1 || $manifest['format'] !== 'wpress' ||
            $manifest['archive'] !== $name || ! is_int( $manifest['bytes'] ) ||
            $manifest['bytes'] < 0 || ! is_string( $manifest['sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $manifest['sha256'] ) ) {
            return array( 'status' => 'invalid', 'reason' => 'Malformed integrity manifest.' );
        }
        clearstatcache( true, $path );
        if ( filesize( $path ) !== $manifest['bytes'] ) {
            return array( 'status' => 'corrupt', 'reason' => 'Archive size differs from manifest.' );
        }
        if ( $verify_contents ) {
            $digest = hash_file( 'sha256', $path );
            if ( $digest === false || ! hash_equals( $manifest['sha256'], $digest ) ) {
                return array( 'status' => 'corrupt', 'reason' => 'Archive checksum differs from manifest.' );
            }
        }
        return array( 'status' => $verify_contents ? 'verified' : 'recorded', 'sha256' => $manifest['sha256'], 'bytes' => $manifest['bytes'] );
    }
}
