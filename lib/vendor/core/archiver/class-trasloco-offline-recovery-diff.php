<?php
/**
 * Offline, read-only comparison of a verified recovery snapshot against the
 * site's current wp-content files. Does not load WordPress, query MySQL,
 * modify files, or infer whether untracked files should be deleted.
 */
class Trasloco_Offline_Recovery_Diff {
    const HEADER = 4377;
    const CHUNK = 1048576;
    const DEFAULT_MAX_BYTES = 2147483648;
    const MAX_FILES = 100000;
    const MAX_DESTINATION_ENTRIES = 200000;
    const CONTENT_GROUPS = array( 'uploads', 'themes', 'plugins', 'mu-plugins' );

    /**
     * @return array Bounded, portable report with relative paths only.
     */
    public static function compare( $archive, $content_root, $max_bytes = self::DEFAULT_MAX_BYTES,
        $scan_untracked = false, $excludes = array() ) {
        if ( ! is_bool( $scan_untracked ) || ! is_int( $max_bytes ) || $max_bytes < 1 ||
             ! is_string( $content_root ) || is_link( $content_root ) ||
             ! is_dir( $content_root ) || ! is_readable( $content_root ) ) {
            throw new InvalidArgumentException( 'A readable, non-symlink wp-content root and a positive byte limit are required.' );
        }
        $excludes = self::validate_excludes( $excludes );
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
        $tracked = array();
        $report = array(
            'integrity' => 'sha256_verified',
            'files_compared' => 0,
            'files_excluded' => 0,
            'exclusions' => $excludes,
            'content_bytes_checked' => 0,
            'identical' => 0,
            'modified' => 0,
            'missing' => 0,
            'unsafe_target' => 0,
            'sample' => array(),
            'untracked_scan' => $scan_untracked ? 'completed' : 'not_requested',
            'not_in_snapshot' => 0,
            'unsafe_untracked' => 0,
            'destination_entries_scanned' => 0,
            'untracked_sample' => array(),
            'warning' => 'Read-only snapshot comparison. Untracked files, when scanned, are NOT proof of an imported-only file or safe deletion. Database changes and concurrent writes are not evaluated. NOT automatic rollback.',
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
                    $relative = $folder . '/' . $name;
                    if ( isset( $tracked[ $relative ] ) ) {
                        throw new RuntimeException( 'Duplicate file path in recovery archive.' );
                    }
                    $tracked[ $relative ] = true;
                    if ( self::is_excluded( $relative, $excludes ) ) {
                        $report['files_excluded']++;
                        $offset += self::HEADER + $length;
                        continue;
                    }
                    if ( $report['files_compared'] >= self::MAX_FILES ||
                         $report['content_bytes_checked'] > $max_bytes - $length ) {
                        throw new RuntimeException( 'Recovery comparison exceeds its file/byte safety limit.' );
                    }
                    $report['files_compared']++;
                    $report['content_bytes_checked'] += $length;
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
        if ( $scan_untracked ) {
            self::scan_untracked( $root, $tracked, $report );
        }
        return $report;
    }

    /** Validate exact relative paths; an excluded directory matches descendants. */
    private static function validate_excludes( $excludes ) {
        if ( ! is_array( $excludes ) || count( $excludes ) > 100 ) {
            throw new InvalidArgumentException( 'Recovery diff accepts at most 100 relative path exclusions.' );
        }
        $validated = array();
        foreach ( $excludes as $exclude ) {
            if ( ! is_string( $exclude ) || $exclude === '' || strlen( $exclude ) > 1024 ||
                strpos( $exclude, '\\' ) !== false || preg_match( '/[\x00-\x1f\x7f]/', $exclude ) ||
                $exclude[0] === '/' || preg_match( '/^[A-Za-z]:/', $exclude ) ) {
                throw new InvalidArgumentException( 'Recovery diff exclusions must be safe relative content paths.' );
            }
            $parts = explode( '/', $exclude );
            if ( ! in_array( $parts[0], self::CONTENT_GROUPS, true ) ||
                in_array( '', $parts, true ) || in_array( '.', $parts, true ) || in_array( '..', $parts, true ) ) {
                throw new InvalidArgumentException( 'Recovery diff exclusions must stay under uploads, themes, plugins or mu-plugins.' );
            }
            $validated[ $exclude ] = true;
        }
        return array_keys( $validated );
    }

    private static function is_excluded( $relative, $excludes ) {
        foreach ( $excludes as $exclude ) {
            if ( $relative === $exclude || strpos( $relative, $exclude . '/' ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Optional read-only inventory of files that have no matching archive
     * entry. This does NOT infer their origin: WordPress and other plugins
     * may have created them legitimately after the recovery snapshot.
     */
    private static function scan_untracked( $root, $tracked, &$report ) {
        foreach ( self::CONTENT_GROUPS as $group ) {
            $group_root = $root . DIRECTORY_SEPARATOR . $group;
            if ( is_link( $group_root ) ) {
                self::add_untracked( $report, $group, 'unsafe_untracked' );
                continue;
            }
            if ( ! file_exists( $group_root ) ) {
                continue;
            }
            if ( ! is_dir( $group_root ) ) {
                self::add_untracked( $report, $group, 'unsafe_untracked' );
                continue;
            }
            try {
                $walk = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $group_root, FilesystemIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ( $walk as $entry ) {
                    $report['destination_entries_scanned']++;
                    if ( $report['destination_entries_scanned'] > self::MAX_DESTINATION_ENTRIES ) {
                        throw new RuntimeException( 'Destination inventory exceeds its safety limit.' );
                    }
                    $path = $entry->getPathname();
                    $relative = $group . '/' . str_replace( DIRECTORY_SEPARATOR, '/',
                        substr( $path, strlen( $group_root ) + 1 ) );
                    // Archive collisions were checked while building $tracked.
                    if ( isset( $tracked[ $relative ] ) ) {
                        continue;
                    }
                    if ( $entry->isLink() ) {
                        self::add_untracked( $report, $relative, 'unsafe_untracked' );
                    } elseif ( $entry->isDir() ) {
                        continue;
                    } elseif ( $entry->isFile() ) {
                        self::add_untracked( $report, $relative, 'not_in_snapshot' );
                    } else {
                        self::add_untracked( $report, $relative, 'unsafe_untracked' );
                    }
                }
            } catch ( UnexpectedValueException $e ) {
                // Inaccessible directories cannot be treated as a complete
                // inventory; fail closed instead of returning zero extras.
                throw new RuntimeException( 'Destination inventory could not be completed.' );
            }
        }
    }

    private static function add_untracked( &$report, $relative, $state ) {
        $report[ $state ]++;
        if ( count( $report['untracked_sample'] ) < 25 ) {
            $report['untracked_sample'][] = array(
                'relative_path' => self::safe_display_path( $relative ),
                'state' => $state,
            );
        }
    }

    /** Keep malicious or non-UTF8 filesystem names out of CLI log controls. */
    private static function safe_display_path( $relative ) {
        $pattern = preg_match( '//u', $relative ) ? '/[\\x00-\\x1f\\x7f]/' : '/[^\\x20-\\x7e]/';
        return preg_replace_callback( $pattern, function ( $match ) {
            return sprintf( '\\x%02X', ord( $match[0] ) );
        }, $relative );
    }
}
