<?php
/**
 * Offline, operator-controlled recovery of ORIGINAL wp-content bytes.
 *
 * Preparation is read-only for the live site: stage the checksum-verified
 * snapshot, inventory replacements, then preserve ALL overwritten files.
 * Application never deletes untracked content or changes SQL. A crash can
 * leave a partial overlay; re-applying the same plan is idempotent.
 *
 * This is NOT a transactional, full-site rollback. Stop all site writers.
 */
class Trasloco_Offline_File_Recovery {
    const VERSION = 1;
    const MAX_PLAN_BYTES = 33554432;
    const MAX_FILES = 100000;
    const MAX_BYTES = 2147483648;
    const GROUPS = array( 'uploads', 'themes', 'plugins', 'mu-plugins' );

    private static function root( $path, $writable = false ) {
        if ( ! is_string( $path ) || is_link( $path ) || ! is_dir( $path ) ||
            ! is_readable( $path ) || ( $writable && ! is_writable( $path ) ) ) {
            throw new RuntimeException( 'A regular, accessible content directory is required.' );
        }
        $real = realpath( $path );
        if ( $real === false ) {
            throw new RuntimeException( 'Cannot resolve content directory.' );
        }
        return $real;
    }

    private static function relative( $path ) {
        if ( ! is_string( $path ) || strlen( $path ) > 4096 ||
            strpos( $path, '\\' ) !== false || preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
            throw new RuntimeException( 'Unsafe relative recovery path.' );
        }
        $parts = explode( '/', $path );
        if ( count( $parts ) < 2 || ! in_array( $parts[0], self::GROUPS, true ) ) {
            throw new RuntimeException( 'Recovery path does not belong to a supported content group.' );
        }
        foreach ( $parts as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                throw new RuntimeException( 'Unsafe recovery path component.' );
            }
        }
        return $parts;
    }

    /** Check every parent; never follow a link when inspecting a live path. */
    private static function target( $root, $relative ) {
        $parts = self::relative( $relative );
        $cursor = $root;
        foreach ( $parts as $index => $part ) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if ( is_link( $cursor ) ) {
                throw new RuntimeException( 'Recovery target contains a symbolic link.' );
            }
            if ( $index < count( $parts ) - 1 && file_exists( $cursor ) && ! is_dir( $cursor ) ) {
                throw new RuntimeException( 'Recovery destination parent is not a directory.' );
            }
        }
        if ( file_exists( $cursor ) && ! is_file( $cursor ) ) {
            throw new RuntimeException( 'Recovery target is not a regular file.' );
        }
        return $cursor;
    }

    private static function recovery_file( $base, $relative ) {
        $segments = self::relative( $relative );
        if ( is_link( $base ) || ! is_dir( $base ) ) {
            throw new RuntimeException( 'Invalid private recovery directory.' );
        }
        $cursor = $base;
        foreach ( $segments as $index => $segment ) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if ( is_link( $cursor ) ||
                ( $index < count( $segments ) - 1 && ! is_dir( $cursor ) ) ) {
                throw new RuntimeException( 'Invalid private recovery subdirectory.' );
            }
        }
        return $cursor;
    }

    private static function file_digest( $path ) {
        if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
            throw new RuntimeException( 'Recovery source or existing file is unsafe.' );
        }
        $digest = @hash_file( 'sha256', $path );
        if ( ! is_string( $digest ) ) {
            throw new RuntimeException( 'Cannot checksum a recovery file.' );
        }
        return $digest;
    }

    /** Private exclusive-copy, for retained original contents and staging. */
    private static function copy_new( $from, $to, $max_bytes ) {
        $in = @fopen( $from, 'rb' );
        $out = @fopen( $to, 'xb' );
        if ( $in === false || $out === false ) {
            if ( $in !== false ) { fclose( $in ); }
            if ( $out !== false ) { fclose( $out ); }
            throw new RuntimeException( 'Cannot create a private recovery file.' );
        }
        try {
            if ( ! @chmod( $to, 0600 ) ) {
                throw new RuntimeException( 'Cannot protect private recovery file.' );
            }
            $copied = stream_copy_to_stream( $in, $out, $max_bytes );
            // Read one byte without writing it: a growing source cannot exceed the budget.
            if ( $copied === false || fread( $in, 1 ) !== '' || ! feof( $in ) || ! fflush( $out ) ) {
                throw new RuntimeException( 'Private recovery file could not be copied.' );
            }
        } catch ( Throwable $e ) {
            fclose( $out );
            fclose( $in );
            @unlink( $to );
            throw $e;
        }
        fclose( $out );
        fclose( $in );
        return $copied;
    }

    private static function file_mode( $path ) {
        clearstatcache( true, $path );
        $mode = @fileperms( $path );
        if ( $mode === false ) {
            throw new RuntimeException( 'Cannot read recovery destination permissions.' );
        }
        return $mode & 0777; // Never propagate setuid, setgid or sticky bits.
    }

    private static function mkdir_parents( $root, $relative, $mode ) {
        $parts = self::relative( $relative );
        array_pop( $parts );
        $cursor = $root;
        foreach ( $parts as $part ) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if ( is_link( $cursor ) || ( file_exists( $cursor ) && ! is_dir( $cursor ) ) ) {
                throw new RuntimeException( 'Recovery path parent changed unexpectedly.' );
            }
            if ( ! is_dir( $cursor ) && ! @mkdir( $cursor, $mode ) ) {
                throw new RuntimeException( 'Cannot create recovery parent directory.' );
            }
        }
    }

    private static function write_plan( $work, $plan ) {
        $path = $work . DIRECTORY_SEPARATOR . 'plan.json';
        if ( is_link( $path ) ) {
            throw new RuntimeException( 'Unsafe recovery plan path.' );
        }
        $data = json_encode( $plan, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $data ) || strlen( $data ) > self::MAX_PLAN_BYTES ) {
            throw new RuntimeException( 'Recovery plan is too large.' );
        }
        $temp = tempnam( $work, '.recovery-plan-' );
        if ( $temp === false ) {
            throw new RuntimeException( 'Cannot create recovery plan.' );
        }
        @chmod( $temp, 0600 );
        if ( file_put_contents( $temp, $data, LOCK_EX ) !== strlen( $data ) ||
            is_link( $path ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot publish recovery plan.' );
        }
    }

    private static function read_plan( $work ) {
        $path = $work . DIRECTORY_SEPARATOR . 'plan.json';
        if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > self::MAX_PLAN_BYTES ) {
            throw new RuntimeException( 'Private recovery plan is unavailable.' );
        }
        $plan = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $plan ) || ! isset( $plan['version'], $plan['state'],
            $plan['content_root'], $plan['archive_sha256'], $plan['files'] ) ||
            $plan['version'] !== self::VERSION ||
            ! in_array( $plan['state'], array( 'prepared', 'applying', 'completed' ), true ) ||
            ! is_array( $plan['files'] ) || count( $plan['files'] ) > self::MAX_FILES ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $plan['archive_sha256'] ) ) {
            throw new RuntimeException( 'Invalid private recovery plan.' );
        }
        return $plan;
    }

    /**
     * Stage ALL recoverable bytes; retain originals for changed files.
     * No write to the destination happens in this step.
     */
    public static function prepare( $archive, $content_root, $workspace,
        $max_bytes = self::MAX_BYTES ) {
        $root = self::root( $content_root );
        if ( ! is_int( $max_bytes ) || $max_bytes < 1 ||
            ! is_string( $workspace ) || file_exists( $workspace ) || is_link( $workspace ) ||
            ! is_dir( dirname( $workspace ) ) || is_link( dirname( $workspace ) ) ) {
            throw new RuntimeException( 'A new private recovery workspace is required.' );
        }
        $parent = realpath( dirname( $workspace ) );
        if ( $parent === false || $parent === $root ||
            strpos( $parent . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) === 0 ) {
            throw new RuntimeException( 'Recovery workspace must be outside wp-content.' );
        }
        require_once __DIR__ . '/class-trasloco-offline-recovery.php';
        $inspection = Trasloco_Offline_Recovery::inspect( $archive );
        if ( $inspection['integrity'] !== 'sha256_verified' ) {
            throw new RuntimeException( 'An SHA-256-verified recovery archive is required.' );
        }
        if ( ! @mkdir( $workspace, 0700 ) ) {
            throw new RuntimeException( 'Cannot create private recovery workspace.' );
        }
        @chmod( $workspace, 0700 );
        $staged = $workspace . DIRECTORY_SEPARATOR . 'snapshot';
        $originals = $workspace . DIRECTORY_SEPARATOR . 'originals';
        try {
            $staging = Trasloco_Offline_Recovery::stage_content( $archive, $staged, $max_bytes );
            $payload_bytes = $staging['bytes_staged'];
            if ( ! @mkdir( $originals, 0700 ) ) {
                throw new RuntimeException( 'Cannot create private originals directory.' );
            }
            $records = array();
            $total = 0;
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $staged, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $files as $item ) {
                if ( $item->isLink() || ! $item->isFile() ) {
                    throw new RuntimeException( 'Unsafe staged recovery content.' );
                }
                $relative = str_replace( DIRECTORY_SEPARATOR, '/',
                    substr( $item->getPathname(), strlen( $staged ) + 1 ) );
                self::relative( $relative );
                $target = self::target( $root, $relative );
                $new_sha = self::file_digest( $item->getPathname() );
                $size = $item->getSize();
                $old_sha = file_exists( $target ) ? self::file_digest( $target ) : null;
                $old_mode = $old_sha !== null ? self::file_mode( $target ) : null;
                if ( $old_sha === $new_sha ) {
                    continue;
                }
                if ( count( $records ) >= self::MAX_FILES || $total > $max_bytes - $size ) {
                    throw new RuntimeException( 'Recovery plan exceeds safety limits.' );
                }
                $total += $size;
                if ( $old_sha !== null ) {
                    clearstatcache( true, $target );
                    $old_size = filesize( $target );
                    if ( $old_size === false || $old_size > $max_bytes - $payload_bytes ) {
                        throw new RuntimeException( 'Recovery payload exceeds safety limits.' );
                    }
                    self::mkdir_parents( $originals, $relative, 0700 );
                    $original = $originals . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
                    $payload_bytes += self::copy_new( $target, $original, $max_bytes - $payload_bytes );
                    if ( self::file_digest( $original ) !== $old_sha ||
                        self::file_digest( $target ) !== $old_sha ) {
                        throw new RuntimeException( 'Destination changed while preserving its original files.' );
                    }
                }
                $records[] = array(
                    'relative_path' => $relative, 'sha256' => $new_sha,
                    'previous_sha256' => $old_sha, 'bytes' => $size, 'previous_mode' => $old_mode,
                );
            }
            // Close the archive race window between initial inspection and staging.
            if ( Trasloco_Offline_Recovery::inspect( $archive )['integrity'] !== 'sha256_verified' ) {
                throw new RuntimeException( 'Recovery archive changed during preparation.' );
            }
            $plan = array( 'version' => self::VERSION, 'state' => 'prepared',
                'content_root' => $root, 'archive_sha256' => self::file_digest( $archive ),
                'files' => $records, 'change_bytes' => $total, 'payload_bytes' => $payload_bytes );
            self::write_plan( $workspace, $plan );
            return array( 'state' => 'prepared', 'files_to_restore' => count( $records ),
                'change_bytes' => $total, 'payload_bytes' => $payload_bytes );
        } catch ( Throwable $e ) {
            // Work is private and intentionally retained for inspection.
            // No destination file was modified.
            throw $e;
        }
    }

    /**
     * Caller must have independently stopped ALL writers to wp-content.
     * No deletes, no SQL writes. A stopped process may be replayed safely.
     */
    public static function apply( $workspace, $content_root ) {
        $root = self::root( $content_root, true );
        if ( ! is_string( $workspace ) || is_link( $workspace ) ||
            ! is_dir( $workspace ) || ! is_writable( $workspace ) ) {
            throw new RuntimeException( 'Recovery workspace is inaccessible.' );
        }
        $work = realpath( $workspace );
        $plan = self::read_plan( $work );
        if ( $plan['content_root'] !== $root ) {
            throw new RuntimeException( 'Recovery plan is bound to a different destination.' );
        }
        if ( $plan['state'] === 'completed' ) {
            return array( 'state' => 'completed', 'files_restored' => 0, 'already_completed' => true );
        }
        $staged = $work . DIRECTORY_SEPARATOR . 'snapshot';
        $originals = $work . DIRECTORY_SEPARATOR . 'originals';
        $changes = array();
        $seen = array();
        // Validate EVERY input and target before starting to replace any file.
        foreach ( $plan['files'] as $record ) {
            if ( ! is_array( $record ) || ! isset( $record['relative_path'], $record['sha256'],
                $record['bytes'] ) || ! array_key_exists( 'previous_sha256', $record ) ||
                ! is_int( $record['bytes'] ) || $record['bytes'] < 0 ||
                ! is_string( $record['sha256'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) ||
                ( $record['previous_sha256'] !== null &&
                  ( ! is_string( $record['previous_sha256'] ) ||
                    ! preg_match( '/^[a-f0-9]{64}$/D', $record['previous_sha256'] ) ) ) ) {
                throw new RuntimeException( 'Recovery plan contains invalid file metadata.' );
            }
            if ( array_key_exists( 'previous_mode', $record ) &&
                ( $record['previous_sha256'] === null ? $record['previous_mode'] !== null :
                  ( ! is_int( $record['previous_mode'] ) || $record['previous_mode'] < 0 ||
                    $record['previous_mode'] > 0777 ) ) ) {
                throw new RuntimeException( 'Recovery plan contains invalid permission metadata.' );
            }
            $relative = $record['relative_path'];
            self::relative( $relative );
            if ( isset( $seen[ $relative ] ) ) {
                throw new RuntimeException( 'Recovery plan repeats a destination path.' );
            }
            $seen[ $relative ] = true;
            $source = self::recovery_file( $staged, $relative );
            if ( self::file_digest( $source ) !== $record['sha256'] ||
                filesize( $source ) !== $record['bytes'] ) {
                throw new RuntimeException( 'Recovery staged file was modified.' );
            }
            $target = self::target( $root, $relative );
            $current = file_exists( $target ) ? self::file_digest( $target ) : null;
            if ( $record['previous_sha256'] !== null ) {
                $old = self::recovery_file( $originals, $relative );
                if ( self::file_digest( $old ) !== $record['previous_sha256'] ) {
                    throw new RuntimeException( 'Preserved original file is unavailable or corrupted.' );
                }
            }
            if ( $current !== $record['sha256'] && $current !== $record['previous_sha256'] ) {
                throw new RuntimeException( 'Destination was independently modified: recovery stopped.' );
            }
            // Old v1 plans have no mode: retain the current destination's permissions.
            $mode = $record['previous_mode'] ?? ( $current !== null ? self::file_mode( $target ) : 0644 );
            $changes[] = array( $relative, $source, $record['sha256'], $record['previous_sha256'], $mode );
        }
        $plan['state'] = 'applying';
        self::write_plan( $work, $plan );
        $restored = 0;
        foreach ( $changes as $change ) {
            list( $relative, $source, $expected, $previous, $mode ) = $change;
            self::mkdir_parents( $root, $relative, 0755 );
            $target = self::target( $root, $relative );
            $current = file_exists( $target ) ? self::file_digest( $target ) : null;
            if ( $current === $expected ) {
                continue; // Replayed after an interrupted successful rename.
            }
            if ( $current !== $previous ) {
                throw new RuntimeException( 'Destination changed after recovery preflight.' );
            }
            $temp = tempnam( dirname( $target ), '.trasloco-restore-' );
            if ( $temp === false ) {
                throw new RuntimeException( 'Cannot allocate atomic destination update.' );
            }
            try {
                if ( ! @chmod( $temp, 0600 ) ) {
                    throw new RuntimeException( 'Cannot protect destination recovery temp file.' );
                }
                $from = fopen( $source, 'rb' );
                $to = fopen( $temp, 'wb' );
                if ( $from === false || $to === false ) {
                    if ( $from !== false ) { fclose( $from ); }
                    if ( $to !== false ) { fclose( $to ); }
                    throw new RuntimeException( 'Cannot write destination recovery temp file.' );
                }
                try {
                    if ( stream_copy_to_stream( $from, $to ) === false || ! fflush( $to ) ) {
                        throw new RuntimeException( 'Recovery copy failed.' );
                    }
                } finally {
                    fclose( $from );
                    fclose( $to );
                }
                if ( self::file_digest( $temp ) !== $expected ||
                    self::target( $root, $relative ) !== $target ||
                    ( file_exists( $target ) ? self::file_digest( $target ) : null ) !== $previous ||
                    // Respect permissions tightened after preparation, too.
                    ! @chmod( $temp, $previous !== null ? $mode & self::file_mode( $target ) : $mode ) ||
                    ! rename( $temp, $target ) ) {
                    throw new RuntimeException( 'Recovery destination changed or atomic rename failed.' );
                }
            } finally {
                @unlink( $temp );
            }
            $restored++;
        }
        $plan['state'] = 'completed';
        self::write_plan( $work, $plan );
        return array( 'state' => 'completed', 'files_restored' => $restored,
            'already_completed' => false, 'untracked_deleted' => 0 );
    }
}
