<?php
/**
 * Offline, read-only comparison of a verified recovery snapshot against the
 * site's current wp-content files. Does not load WordPress, query MySQL,
 * modify files, or infer which untracked files should be deleted.
 */
class Trasloco_Offline_Recovery_Diff {
    const HEADER = 4377;
    const CHUNK = 1048576;
    const DEFAULT_MAX_BYTES = 2147483648;
    const MAX_FILES = 100000;
    const CONTENT_GROUPS = array( 'uploads', 'themes', 'plugins', 'mu-plugins' );

    /**
     * @return array Bounded, portable report with relative paths only.
     */
    public static function compare( $archive, $content_root, $max_bytes = self::DEFAULT_MAX_BYTES ) {
        if ( ! is_int( $max_bytes ) || $max_bytes < 1 ||
             ! is_string( $content_root ) || is_link( $content_root ) ||
             ! is_dir( $content_root ) || ! is_readable( $content_root ) ) {
            throw new InvalidArgumentException( 'A readable, non-symlink wp-content root and a positive byte limit are required.' );
        }
        $root = realpath( $content_root );
        if ( $root === false ) {
            throw new RuntimeException( 'Unable to inspect the content root.' );
        }
        require_once __DIR__ . '/class-trasloco-offline-recovery.php';
        $inspection = Trasloco_Offline_Recovery::inspect( $archive );
        if ( $inspection['integrity'] !== 'sha256_verified' ) {
            throw new RuntimeException( 'An independently SHA-256-verified recovery archive is required.' );
        }
        clearstatcache( true, $archive );
        $archive_bytes = filesize( $archive );
        $archive_modified = filemtime( $archive );
        $handle = @fopen( $archive, 'rb' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Cannot open the recovery archive.' );
        }
        $report = array(
            'integrity' => 'sha256_verified',
            'files_compared' => 0,
            'content_bytes_checked' => 0,
            'identical' => 0,
            'modified' => 0,
            'missing' => 0,
            'unsafe_target' => 0,
            'sample' => array(),
            'warning' => 'Read-only snapshot comparison of archived content. Extra files, database state, concurrent writes and safe deletions are NOT evaluated. This is not automatic rollback.',
        );
        $offset = 0;
        try {
            while ( $offset < $archive_bytes - self::HEADER ) {
                if ( fseek( $handle, $offset, SEEK_SET ) !== 0 ) {
                    throw new RuntimeException( 'Could not seek the recovery archive.' );
                }
                $header = fread( $handle, self::HEADER );
                if ( ! is_string( $header ) || strlen( $header ) !== self::HEADER ) {
                    throw new RuntimeException( 'The recovery archive changed while comparing.' );
                }
                $name = trim( substr( $header, 0, 255 ), "\0 " );
                $size_string = trim( substr( $header, 255, 14 ), "\0 " );
                $folder = substr( $header, 281, 4096 );
                $nul = strpos( $folder, "\0" );
                $folder = $nul === false ? substr( $folder, 0, -8 ) : substr( $folder, 0, $nul );
                $folder = str_replace( '\\', '/', trim( $folder ) );
                if ( $folder === '.' ) {
                    $folder = '';
                }
                // Repeat security checks instead of assuming a previous scan
                // precludes an archive swap between inspect() and this read.
                $parts = $folder === '' ? array() : explode( '/', $folder );
                if ( ! ctype_digit( $size_string ) || $name === '' ||
                    $name === '.' || $name === '..' ||
                    strpbrk( $name, "/\\") !== false ||
                    preg_match( '/[\x00-\x1f\x7f]/', $name . $folder ) ||
                    preg_match( '#^(/|[A-Za-z]:)#', $folder ) ||
                    in_array( '', $parts, true ) ||
                    in_array( '.', $parts, true ) ||
                    in_array( '..', $parts, true ) ) {
                    throw new RuntimeException( 'Invalid recovery archive entry.' );
                }
                $length = filter_var( $size_string, FILTER_VALIDATE_INT,
                    array( 'options' => array( 'min_range' => 0 ) ) );
                if ( $length === false || $length > $archive_bytes - $offset - ( 2 * self::HEADER ) ) {
                    throw new RuntimeException( 'Invalid recovery entry size.' );
                }
                if ( count( $parts ) > 0 && in_array( $parts[0], self::CONTENT_GROUPS, true ) ) {
                    if ( $report['files_compared'] >= self::MAX_FILES ||
                         $report['content_bytes_checked'] > $max_bytes - $length ) {
                        throw new RuntimeException( 'Recovery comparison exceeds its file/byte safety limit.' );
                    }
                    $report['files_compared']++;
                    $report['content_bytes_checked'] += $length;
                    $relative = $folder . '/' . $name;
                    $cursor = $root;
                    $unsafe = false;
                    foreach ( $parts as $part ) {
                        $cursor .= DIRECTORY_SEPARATOR . $part;
                        if ( is_link( $cursor ) ) {
                            $unsafe = true;
                            break;
                        }
                        if ( file_exists( $cursor ) && ! is_dir( $cursor ) ) {
                            $unsafe = true;
                            break;
                        }
                    }
                    $target = $cursor . DIRECTORY_SEPARATOR . $name;
                    if ( ! $unsafe && is_link( $target ) ) {
                        $unsafe = true;
                    }
                    if ( $unsafe || ( file_exists( $target ) && ! is_file( $target ) ) ) {
                        $state = 'unsafe_target';
                    } elseif ( ! is_file( $target ) ) {
                        $state = 'missing';
                    } elseif ( ! is_readable( $target ) || filesize( $target ) === false ) {
                        $state = 'unsafe_target';
                    } elseif ( filesize( $target ) !== $length ) {
                        $state = 'modified';
                    } else {
                        // Compare actual bytes, not just filename/size/mtime.
                        // Reading happens in fixed blocks regardless of size.
                        $digest = hash_init( 'sha256' );
                        $remaining = $length;
                        while ( $remaining > 0 ) {
                            $piece = fread( $handle, min( self::CHUNK, $remaining ) );
                            if ( ! is_string( $piece ) || $piece === '' ) {
                                throw new RuntimeException( 'Recovery archive changed during hashing.' );
                            }
                            hash_update( $digest, $piece );
                            $remaining -= strlen( $piece );
                        }
                        $expected = hash_final( $digest );
                        $actual = @hash_file( 'sha256', $target );
                        clearstatcache( true, $target );
                        $state = is_string( $actual ) && ! is_link( $target ) &&
                            is_file( $target ) && filesize( $target ) === $length &&
                            hash_equals( $expected, $actual ) ? 'identical' : 'modified';
                    }
                    $report[ $state ]++;
                    if ( $state !== 'identical' && count( $report['sample'] ) < 25 ) {
                        $report['sample'][] = array( 'relative_path' => $relative, 'state' => $state );
                    }
                }
                $offset += self::HEADER + $length;
            }
        } finally {
            fclose( $handle );
        }
        clearstatcache( true, $archive );
        if ( $offset !== $archive_bytes - self::HEADER ||
             filesize( $archive ) !== $archive_bytes || filemtime( $archive ) !== $archive_modified ) {
            throw new RuntimeException( 'Recovery archive changed while comparing.' );
        }
        return $report;
    }
}
