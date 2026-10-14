<?php
/**
 * Offline, operator-controlled recovery of ORIGINAL wp-content bytes.
 *
 * Preparation is read-only for the live site: stage the checksum-verified
 * snapshot, inventory replacements, then preserve ALL overwritten files.
 * Application only quarantines archive-absent files after explicit review;
 * it never writes SQL. A crash can leave a partial overlay; re-applying the
 * same plan is idempotent.
 *
 * This is NOT a transactional, full-site rollback. Stop all site writers.
 */
class Trasloco_Offline_File_Recovery {
    const VERSION = 1;
    const MAX_PLAN_BYTES = 33554432;
    const MAX_FILES = 100000;
    const MAX_DESTINATION_ENTRIES = 200000;
    const MAX_PACKAGE_BYTES = 1048576;
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
    private static function sync_stream( $stream, $required ) {
        if ( ! fflush( $stream ) ||
            ( $required && ( ! function_exists( 'fsync' ) || ! @fsync( $stream ) ) ) ) {
            throw new RuntimeException( 'Recovery data could not be durably synchronized.' );
        }
    }

    private static function sync_directory( $directory, $required ) {
        if ( ! $required ) {
            return;
        }
        $handle = @fopen( $directory, 'r' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Recovery directory cannot be synchronized.' );
        }
        try {
            if ( ! function_exists( 'fsync' ) || ! @fsync( $handle ) ) {
                throw new RuntimeException( 'Recovery directory cannot be synchronized.' );
            }
        } finally {
            fclose( $handle );
        }
    }

    private static function sync_file_path( $path, $required ) {
        if ( ! $required ) {
            return;
        }
        $handle = @fopen( $path, 'rb' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Recovery file cannot be synchronized.' );
        }
        try {
            if ( ! function_exists( 'fsync' ) || ! @fsync( $handle ) ) {
                throw new RuntimeException( 'Recovery file cannot be synchronized.' );
            }
        } finally {
            fclose( $handle );
        }
    }

    /** Fail before touching a destination when strict file/directory sync is unavailable. */
    private static function require_fsync_support( $directory ) {
        if ( ! function_exists( 'fsync' ) || ! function_exists( 'stream_is_local' ) ||
            ! stream_is_local( $directory ) ) {
            throw new RuntimeException( 'Strict fsync recovery requires local streams and PHP fsync support.' );
        }
        self::sync_directory( $directory, true );
        $probe = @tempnam( $directory, '.trasloco-fsync-probe-' );
        if ( $probe === false ) {
            throw new RuntimeException( 'Cannot probe strict recovery durability.' );
        }
        $handle = @fopen( $probe, 'wb' );
        try {
            if ( $handle === false ) {
                throw new RuntimeException( 'Cannot probe strict recovery durability.' );
            }
            self::sync_stream( $handle, true );
        } finally {
            if ( is_resource( $handle ) ) { fclose( $handle ); }
            @unlink( $probe );
            self::sync_directory( $directory, true );
        }
    }

    private static function copy_new( $from, $to, $max_bytes, $require_fsync = false ) {
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
            if ( $copied === false || fread( $in, 1 ) !== '' || ! feof( $in ) ) {
                throw new RuntimeException( 'Private recovery file could not be copied.' );
            }
            self::sync_stream( $out, $require_fsync );
        } catch ( Throwable $e ) {
            fclose( $out );
            fclose( $in );
            @unlink( $to );
            throw $e;
        }
        fclose( $out );
        fclose( $in );
        self::sync_directory( dirname( $to ), $require_fsync );
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

    /** Read bounded package metadata needed to avoid intentional exclusions. */
    private static function package_metadata( $archive, $entry ) {
        if ( ! isset( $entry['files']['package.json']['offset'], $entry['files']['package.json']['size'] ) ||
            ! is_int( $entry['files']['package.json']['offset'] ) ||
            ! is_int( $entry['files']['package.json']['size'] ) ||
            $entry['files']['package.json']['size'] < 2 ||
            $entry['files']['package.json']['size'] > self::MAX_PACKAGE_BYTES ) {
            throw new RuntimeException( 'Recovery package metadata is invalid or too large.' );
        }
        $handle = @fopen( $archive, 'rb' );
        if ( $handle === false ) {
            throw new RuntimeException( 'Cannot read recovery package metadata.' );
        }
        try {
            if ( fseek( $handle, $entry['files']['package.json']['offset'], SEEK_SET ) !== 0 ) {
                throw new RuntimeException( 'Cannot seek to recovery package metadata.' );
            }
            $body = fread( $handle, $entry['files']['package.json']['size'] );
        } finally {
            fclose( $handle );
        }
        $package = is_string( $body ) ? json_decode( $body, true ) : null;
        if ( ! is_array( $package ) || json_last_error() !== JSON_ERROR_NONE ) {
            throw new RuntimeException( 'Recovery package metadata is malformed.' );
        }
        return $package;
    }

    /** Candidates cannot be inferred safely from an intentionally partial backup. */
    private static function require_complete_content_package( $package ) {
        foreach ( array( 'NoMedia', 'NoThemes', 'NoInactiveThemes', 'NoMustUsePlugins',
            'NoPlugins', 'NoInactivePlugins', 'NoCache' ) as $selection ) {
            if ( ! empty( $package[ $selection ] ) ) {
                throw new RuntimeException( 'Archive-absent quarantine requires a backup without content exclusions.' );
            }
        }
        $plugin_dir = $package['TraslocoPluginDir'] ?? 'trasloco';
        if ( ! is_string( $plugin_dir ) || strpos( $plugin_dir, '\\' ) !== false ||
            preg_match( '/[\x00-\x1f\x7f]/', $plugin_dir ) ) {
            throw new RuntimeException( 'Recovery package contains an unsafe Trasloco plugin directory.' );
        }
        $preserved_paths = array();
        if ( $plugin_dir !== '' ) {
            $plugin_path = 'plugins/' . $plugin_dir;
            self::relative( $plugin_path );
            $preserved_paths[] = $plugin_path;
        }

        if ( array_key_exists( 'ContentExcludedPaths', $package ) ) {
            if ( ! is_array( $package['ContentExcludedPaths'] ) ) {
                throw new RuntimeException( 'Recovery package content exclusions are malformed.' );
            }
            foreach ( $package['ContentExcludedPaths'] as $excluded ) {
                if ( ! is_string( $excluded ) || $excluded === '' ||
                    preg_match( '/[\x00-\x1f\x7f]/', $excluded ) ) {
                    throw new RuntimeException( 'Recovery package contains an unsafe content exclusion.' );
                }
                $excluded = str_replace( '\\', '/', $excluded );
                if ( $excluded[0] === '/' || preg_match( '/^[a-zA-Z]:/', $excluded ) ) {
                    throw new RuntimeException( 'Recovery package contains an absolute content exclusion.' );
                }
                $parts = explode( '/', $excluded );
                if ( in_array( '..', $parts, true ) || in_array( '.', $parts, true ) ) {
                    throw new RuntimeException( 'Recovery package contains an unsafe content exclusion.' );
                }
                // Only the four roots scanned for quarantine can contribute
                // candidates. Other export exclusions are outside this tool's
                // scope (for example package.json or cache metadata).
                if ( in_array( $parts[0], self::GROUPS, true ) ) {
                    $preserved_paths[] = implode( '/', $parts );
                }
            }
        }
        return array_values( array_unique( $preserved_paths ) );
    }

    private static function mkdir_parents( $root, $relative, $mode, $require_fsync = false ) {
        $parts = self::relative( $relative );
        array_pop( $parts );
        $cursor = $root;
        foreach ( $parts as $part ) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if ( is_link( $cursor ) || ( file_exists( $cursor ) && ! is_dir( $cursor ) ) ) {
                throw new RuntimeException( 'Recovery path parent changed unexpectedly.' );
            }
            if ( ! is_dir( $cursor ) ) {
                if ( ! @mkdir( $cursor, $mode ) ) {
                    throw new RuntimeException( 'Cannot create recovery parent directory.' );
                }
            }
            self::sync_directory( dirname( $cursor ), $require_fsync );
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
        $required = ( $plan['durability'] ?? 'best_effort' ) === 'fsync_required';
        $handle = @fopen( $temp, 'wb' );
        if ( $handle === false ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot write recovery plan.' );
        }
        try {
            $offset = 0;
            while ( $offset < strlen( $data ) ) {
                $written = fwrite( $handle, substr( $data, $offset ) );
                if ( $written === false || $written === 0 ) {
                    throw new RuntimeException( 'Cannot write recovery plan.' );
                }
                $offset += $written;
            }
            self::sync_stream( $handle, $required );
        } catch ( Throwable $e ) {
            fclose( $handle );
            @unlink( $temp );
            throw $e;
        }
        fclose( $handle );
        if ( is_link( $path ) || ! @rename( $temp, $path ) ) {
            @unlink( $temp );
            throw new RuntimeException( 'Cannot publish recovery plan.' );
        }
        self::sync_directory( $work, $required );
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
            ! is_string( $plan['content_root'] ) || $plan['content_root'] === '' ||
            $plan['content_root'][0] !== DIRECTORY_SEPARATOR ||
            ! is_string( $plan['archive_sha256'] ) ||
            ( isset( $plan['durability'] ) && ! in_array( $plan['durability'],
                array( 'best_effort', 'fsync_required' ), true ) ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $plan['archive_sha256'] ) ) {
            throw new RuntimeException( 'Invalid private recovery plan.' );
        }
        $quarantine_files = $plan['quarantine_files'] ?? array();
        if ( ! is_array( $quarantine_files ) || count( $quarantine_files ) > self::MAX_FILES ) {
            throw new RuntimeException( 'Invalid private quarantine plan.' );
        }
        $quarantine_seen = array();
        foreach ( $quarantine_files as $record ) {
            if ( ! is_array( $record ) || ! isset( $record['relative_path'], $record['sha256'],
                $record['bytes'], $record['mode'] ) || ! is_int( $record['bytes'] ) ||
                $record['bytes'] < 0 || ! is_int( $record['mode'] ) || $record['mode'] < 0 ||
                $record['mode'] > 0777 || ! is_string( $record['sha256'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) ) {
                throw new RuntimeException( 'Invalid private quarantine file metadata.' );
            }
            self::relative( $record['relative_path'] );
            if ( isset( $quarantine_seen[ $record['relative_path'] ] ) ) {
                throw new RuntimeException( 'Quarantine plan repeats a destination path.' );
            }
            $quarantine_seen[ $record['relative_path'] ] = true;
        }
        $fingerprint = $quarantine_files ? self::approval_sha256( $quarantine_files,
            $plan['content_root'], $plan['archive_sha256'] ) : null;
        if ( array_key_exists( 'quarantine_approval_sha256', $plan ) &&
            $plan['quarantine_approval_sha256'] !== $fingerprint ) {
            throw new RuntimeException( 'Quarantine review list changed after preparation.' );
        }
        // Version-1 plans created before quarantine had no quarantine metadata.
        if ( $quarantine_files && ! array_key_exists( 'quarantine_approval_sha256', $plan ) ) {
            throw new RuntimeException( 'Quarantine plan has no operator approval fingerprint.' );
        }
        return $plan;
    }

    /**
     * Stage ALL recoverable bytes; retain originals for changed files.
     * No write to the destination happens in this step.
     */
    public static function prepare( $archive, $content_root, $workspace,
        $max_bytes = self::MAX_BYTES, $include_untracked = false, $require_fsync = false ) {
        $root = self::root( $content_root );
        if ( ! is_int( $max_bytes ) || $max_bytes < 1 || ! is_bool( $include_untracked ) ||
            ! is_bool( $require_fsync ) ||
            ! is_string( $workspace ) || file_exists( $workspace ) || is_link( $workspace ) ||
            ! is_dir( dirname( $workspace ) ) || is_link( dirname( $workspace ) ) ) {
            throw new RuntimeException( 'A new private recovery workspace is required.' );
        }
        $parent = realpath( dirname( $workspace ) );
        if ( $parent === false || $parent === $root ||
            strpos( $parent . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) === 0 ) {
            throw new RuntimeException( 'Recovery workspace must be outside wp-content.' );
        }
        if ( $require_fsync ) {
            self::require_fsync_support( $parent );
        }
        require_once __DIR__ . '/class-trasloco-offline-recovery.php';
        $archive_sha256 = self::file_digest( $archive );
        $inspection = Trasloco_Offline_Recovery::inspect( $archive );
        if ( $inspection['integrity'] !== 'sha256_verified' ||
            self::file_digest( $archive ) !== $archive_sha256 ) {
            throw new RuntimeException( 'An SHA-256-verified recovery archive is required.' );
        }
        $preserved_paths = array();
        if ( $include_untracked ) {
            $preserved_paths = self::require_complete_content_package(
                self::package_metadata( $archive, $inspection )
            );
        }
        if ( ! @mkdir( $workspace, 0700 ) ) {
            throw new RuntimeException( 'Cannot create private recovery workspace.' );
        }
        @chmod( $workspace, 0700 );
        self::sync_directory( $parent, $require_fsync );
        $staged = $workspace . DIRECTORY_SEPARATOR . 'snapshot';
        $originals = $workspace . DIRECTORY_SEPARATOR . 'originals';
        $quarantine = $workspace . DIRECTORY_SEPARATOR . 'quarantine';
        try {
            $staging = Trasloco_Offline_Recovery::stage_content( $archive, $staged, $max_bytes, $require_fsync );
            $payload_bytes = $staging['bytes_staged'];
            if ( ! @mkdir( $originals, 0700 ) ) {
                throw new RuntimeException( 'Cannot create private originals directory.' );
            }
            self::sync_directory( $workspace, $require_fsync );
            if ( ! @mkdir( $quarantine, 0700 ) ) {
                throw new RuntimeException( 'Cannot create private quarantine directory.' );
            }
            self::sync_directory( $workspace, $require_fsync );
            $records = array();
            $tracked = array();
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
                $tracked[ $relative ] = true;
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
                    self::mkdir_parents( $originals, $relative, 0700, $require_fsync );
                    $original = $originals . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
                    $payload_bytes += self::copy_new( $target, $original,
                        $max_bytes - $payload_bytes, $require_fsync );
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
            $quarantine_records = array();
            if ( $include_untracked ) {
                $quarantine_result = self::stage_untracked( $root, $tracked, $quarantine,
                    $payload_bytes, $max_bytes, $preserved_paths, $require_fsync );
                $quarantine_records = $quarantine_result['files'];
                $payload_bytes += $quarantine_result['bytes'];
            }
            // Close the archive race window between initial inspection and staging.
            if ( Trasloco_Offline_Recovery::inspect( $archive )['integrity'] !== 'sha256_verified' ||
                self::file_digest( $archive ) !== $archive_sha256 ) {
                throw new RuntimeException( 'Recovery archive changed during preparation.' );
            }
            $approval_sha256 = $quarantine_records ? self::approval_sha256(
                $quarantine_records, $root, $archive_sha256
            ) : null;
            $plan = array( 'version' => self::VERSION, 'state' => 'prepared',
                'content_root' => $root, 'archive_sha256' => $archive_sha256,
                'durability' => $require_fsync ? 'fsync_required' : 'best_effort',
                'files' => $records, 'quarantine_files' => $quarantine_records,
                'quarantine_approval_sha256' => $approval_sha256,
                'change_bytes' => $total, 'payload_bytes' => $payload_bytes );
            self::write_plan( $workspace, $plan );
            return array( 'state' => 'prepared', 'files_to_restore' => count( $records ),
                'change_bytes' => $total, 'payload_bytes' => $payload_bytes,
                'quarantine_candidates' => count( $quarantine_records ),
                'approval_sha256' => $approval_sha256,
                'durability' => $require_fsync ? 'fsync_required' : 'best_effort' );
        } catch ( Throwable $e ) {
            // Work is private and intentionally retained for inspection.
            // No destination file was modified.
            throw $e;
        }
    }

    /** Copy every archive-absent regular content file into a private quarantine. */
    private static function stage_untracked( $root, $tracked, $quarantine, $payload_bytes, $max_bytes,
        $preserved_paths, $require_fsync ) {
        $records = array();
        $bytes = 0;
        $entries = 0;
        foreach ( self::GROUPS as $group ) {
            $group_root = $root . DIRECTORY_SEPARATOR . $group;
            if ( is_link( $group_root ) ) {
                throw new RuntimeException( 'Cannot quarantine content through a symbolic link.' );
            }
            if ( ! file_exists( $group_root ) ) {
                continue;
            }
            if ( ! is_dir( $group_root ) ) {
                throw new RuntimeException( 'Content group is not a regular directory.' );
            }
            try {
                $walk = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $group_root, FilesystemIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ( $walk as $item ) {
                    if ( ++$entries > self::MAX_DESTINATION_ENTRIES ) {
                        throw new RuntimeException( 'Destination inventory exceeds its safety limit.' );
                    }
                    $path = $item->getPathname();
                    $relative = $group . '/' . str_replace( DIRECTORY_SEPARATOR, '/',
                        substr( $path, strlen( $group_root ) + 1 ) );
                    self::relative( $relative );
                    if ( $item->isLink() ) {
                        throw new RuntimeException( 'Cannot quarantine a symbolic link or linked directory.' );
                    }
                    if ( $item->isDir() ) {
                        continue;
                    }
                    if ( ! $item->isFile() ) {
                        throw new RuntimeException( 'Destination contains a non-regular content entry.' );
                    }
                    if ( isset( $tracked[ $relative ] ) ) {
                        continue;
                    }
                    $preserved = false;
                    foreach ( $preserved_paths as $prefix ) {
                        if ( $relative === $prefix || strpos( $relative, $prefix . '/' ) === 0 ) {
                            $preserved = true;
                            break;
                        }
                    }
                    if ( $preserved ) {
                        continue;
                    }
                    if ( count( $records ) >= self::MAX_FILES ) {
                        throw new RuntimeException( 'Archive-absent file count exceeds its safety limit.' );
                    }
                    $target = self::target( $root, $relative );
                    $digest = self::file_digest( $target );
                    clearstatcache( true, $target );
                    $size = filesize( $target );
                    $mode = self::file_mode( $target );
                    if ( $size === false || $size > $max_bytes - $payload_bytes - $bytes ) {
                        throw new RuntimeException( 'Recovery payload including quarantine exceeds its safety limit.' );
                    }
                    $destination = $quarantine . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
                    self::mkdir_parents( $quarantine, $relative, 0700, $require_fsync );
                    $copied = self::copy_new( $target, $destination,
                        $max_bytes - $payload_bytes - $bytes, $require_fsync );
                    if ( $copied !== $size || self::file_digest( $destination ) !== $digest ||
                        self::file_digest( $target ) !== $digest ) {
                        throw new RuntimeException( 'Archive-absent destination changed while preserving quarantine.' );
                    }
                    $records[] = array( 'relative_path' => $relative, 'sha256' => $digest,
                        'bytes' => $size, 'mode' => $mode );
                    $bytes += $size;
                }
            } catch ( UnexpectedValueException $e ) {
                throw new RuntimeException( 'Destination inventory could not be completed.' );
            }
        }
        usort( $records, function ( $left, $right ) {
            return strcmp( $left['relative_path'], $right['relative_path'] );
        } );
        return array( 'files' => $records, 'bytes' => $bytes );
    }

    /** Fingerprint the exact path/checksum/size/mode set an operator reviewed. */
    private static function approval_sha256( $records, $content_root, $archive_sha256 ) {
        $approved = array();
        foreach ( $records as $record ) {
            $approved[] = array( $record['relative_path'], $record['sha256'], $record['bytes'], $record['mode'] );
        }
        usort( $approved, function ( $left, $right ) {
            return strcmp( $left[0], $right[0] );
        } );
        $encoded = json_encode( array( 'content_root' => $content_root,
            'archive_sha256' => $archive_sha256, 'files' => $approved ), JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) ) {
            throw new RuntimeException( 'Cannot fingerprint the quarantine review list.' );
        }
        return hash( 'sha256', $encoded );
    }

    /** Return the complete candidate list and approval token for human review. */
    public static function review( $workspace ) {
        if ( ! is_string( $workspace ) || is_link( $workspace ) || ! is_dir( $workspace ) ) {
            throw new RuntimeException( 'Recovery workspace is inaccessible.' );
        }
        $work = realpath( $workspace );
        if ( $work === false ) {
            throw new RuntimeException( 'Recovery workspace cannot be resolved.' );
        }
        $plan = self::read_plan( $work );
        $files = $plan['quarantine_files'] ?? array();
        $fingerprint = $files ? self::approval_sha256(
            $files, $plan['content_root'], $plan['archive_sha256']
        ) : null;
        if ( $fingerprint !== ( $plan['quarantine_approval_sha256'] ?? null ) ) {
            throw new RuntimeException( 'Quarantine review list does not match its prepared approval fingerprint.' );
        }
        return array( 'content_root' => $plan['content_root'],
            'archive_sha256' => $plan['archive_sha256'],
            'quarantine_files' => $files, 'approval_sha256' => $fingerprint );
    }

    /**
     * Caller must independently stop ALL writers to wp-content.
     * Only explicitly approved archive-absent files may be quarantined;
     * there are no SQL writes. A stopped process may be replayed safely.
     */
    /** Serialize concurrent operators applying the same private workspace. */
    public static function apply( $workspace, $content_root, $approval_token = null ) {
        if ( ! is_string( $workspace ) || is_link( $workspace ) || ! is_dir( $workspace ) ) {
            throw new RuntimeException( 'Recovery workspace is inaccessible.' );
        }
        $work = realpath( $workspace );
        if ( $work === false ) {
            throw new RuntimeException( 'Recovery workspace cannot be resolved.' );
        }
        $lock_path = $work . DIRECTORY_SEPARATOR . '.apply.lock';
        if ( is_link( $lock_path ) ) {
            throw new RuntimeException( 'Unsafe recovery lock path.' );
        }
        $lock = @fopen( $lock_path, 'c+b' );
        if ( $lock === false ) {
            throw new RuntimeException( 'Cannot open recovery workspace lock.' );
        }
        try {
            $opened = fstat( $lock );
            if ( is_link( $lock_path ) || $opened === false ||
                ! isset( $opened['mode'], $opened['nlink'] ) ||
                ( $opened['mode'] & 0170000 ) !== 0100000 || $opened['nlink'] !== 1 ||
                ! @chmod( $lock_path, 0600 ) ) {
                throw new RuntimeException( 'Recovery workspace lock is unsafe.' );
            }
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                throw new RuntimeException( 'Another recovery process is applying this plan.' );
            }
            return self::apply_locked( $workspace, $content_root, $approval_token );
        } finally {
            if ( is_resource( $lock ) ) {
                @flock( $lock, LOCK_UN );
                fclose( $lock );
            }
        }
    }

    /** Restore quarantined files only into their original vacant paths. */
    public static function restore_quarantine( $workspace, $content_root, $approval_token ) {
        if ( ! is_string( $workspace ) || is_link( $workspace ) || ! is_dir( $workspace ) ) {
            throw new RuntimeException( 'Recovery workspace is inaccessible.' );
        }
        $work = realpath( $workspace );
        if ( $work === false ) {
            throw new RuntimeException( 'Recovery workspace cannot be resolved.' );
        }
        $lock_path = $work . DIRECTORY_SEPARATOR . '.apply.lock';
        if ( is_link( $lock_path ) ) {
            throw new RuntimeException( 'Unsafe recovery lock path.' );
        }
        $lock = @fopen( $lock_path, 'c+b' );
        if ( $lock === false ) {
            throw new RuntimeException( 'Cannot open recovery workspace lock.' );
        }
        try {
            $opened = fstat( $lock );
            if ( is_link( $lock_path ) || $opened === false ||
                ! isset( $opened['mode'], $opened['nlink'] ) ||
                ( $opened['mode'] & 0170000 ) !== 0100000 || $opened['nlink'] !== 1 ||
                ! @chmod( $lock_path, 0600 ) ) {
                throw new RuntimeException( 'Recovery workspace lock is unsafe.' );
            }
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                throw new RuntimeException( 'Another recovery process is applying this plan.' );
            }
            return self::restore_quarantine_locked( $workspace, $content_root, $approval_token );
        } finally {
            if ( is_resource( $lock ) ) {
                @flock( $lock, LOCK_UN );
                fclose( $lock );
            }
        }
    }

    private static function restore_quarantine_locked( $workspace, $content_root, $approval_token ) {
        $root = self::root( $content_root, true );
        $work = realpath( $workspace );
        $plan = self::read_plan( $work );
        if ( $plan['content_root'] !== $root || $plan['state'] !== 'completed' ) {
            throw new RuntimeException( 'Only a completed plan for this destination can restore quarantine.' );
        }
        $records = $plan['quarantine_files'] ?? array();
        $approval = $records ? self::approval_sha256( $records,
            $plan['content_root'], $plan['archive_sha256'] ) : null;
        if ( ! $records || $approval !== ( $plan['quarantine_approval_sha256'] ?? null ) ||
            ! is_string( $approval_token ) || ! hash_equals( $approval, $approval_token ) ) {
            throw new RuntimeException( 'Exact quarantine review approval is required to restore files.' );
        }
        $require_fsync = ( $plan['durability'] ?? 'best_effort' ) === 'fsync_required';
        if ( $require_fsync ) {
            self::require_fsync_support( $work );
            self::sync_directory( $root, true );
        }
        $quarantine = $work . DIRECTORY_SEPARATOR . 'quarantine';
        $changes = array();
        foreach ( $records as $record ) {
            $relative = $record['relative_path'];
            $source = self::recovery_file( $quarantine, $relative );
            if ( self::file_digest( $source ) !== $record['sha256'] ||
                filesize( $source ) !== $record['bytes'] ) {
                throw new RuntimeException( 'Quarantined file copy is unavailable or corrupted.' );
            }
            $target = self::target( $root, $relative );
            $current = file_exists( $target ) ? self::file_digest( $target ) : null;
            if ( $current !== null && $current !== $record['sha256'] ) {
                throw new RuntimeException( 'Quarantine restore refuses to overwrite newer destination data.' );
            }
            $changes[] = array( $relative, $source, $record['sha256'], $record['mode'] );
        }
        $restored = 0;
        foreach ( $changes as $change ) {
            list( $relative, $source, $expected, $mode ) = $change;
            self::mkdir_parents( $root, $relative, 0755, $require_fsync );
            $target = self::target( $root, $relative );
            if ( file_exists( $target ) ) {
                if ( self::file_digest( $target ) === $expected ) {
                    self::sync_directory( dirname( $target ), $require_fsync );
                    continue;
                }
                throw new RuntimeException( 'Quarantine restore refuses to overwrite newer destination data.' );
            }
            $temp = tempnam( dirname( $target ), '.trasloco-quarantine-restore-' );
            if ( $temp === false ) {
                throw new RuntimeException( 'Cannot allocate quarantine restore file.' );
            }
            try {
                if ( ! @chmod( $temp, 0600 ) ) {
                    throw new RuntimeException( 'Cannot protect quarantine restore file.' );
                }
                $from = @fopen( $source, 'rb' );
                $to = @fopen( $temp, 'wb' );
                if ( $from === false || $to === false ) {
                    if ( $from !== false ) { fclose( $from ); }
                    if ( $to !== false ) { fclose( $to ); }
                    throw new RuntimeException( 'Cannot create quarantine restore file.' );
                }
                try {
                    if ( stream_copy_to_stream( $from, $to ) === false ) {
                        throw new RuntimeException( 'Cannot copy quarantined file back to the site.' );
                    }
                    self::sync_stream( $to, $require_fsync );
                } finally {
                    fclose( $from );
                    fclose( $to );
                }
                if ( self::file_digest( $temp ) !== $expected ||
                    self::target( $root, $relative ) !== $target || file_exists( $target ) ||
                    is_link( $target ) || ! @chmod( $temp, $mode ) ) {
                    throw new RuntimeException( 'Quarantine restore target changed or atomic rename failed.' );
                }
                self::sync_file_path( $temp, $require_fsync );
                if ( ! @rename( $temp, $target ) ) {
                    throw new RuntimeException( 'Quarantine restore target changed or atomic rename failed.' );
                }
                self::sync_directory( dirname( $target ), $require_fsync );
            } finally {
                @unlink( $temp );
            }
            $restored++;
        }
        return array( 'state' => 'completed', 'files_restored' => $restored,
            'approval_sha256' => $approval );
    }

    private static function apply_locked( $workspace, $content_root, $approval_token = null ) {
        $root = self::root( $content_root, true );
        if ( ! is_string( $workspace ) || is_link( $workspace ) ||
            ! is_dir( $workspace ) || ! is_writable( $workspace ) ) {
            throw new RuntimeException( 'Recovery workspace is inaccessible.' );
        }
        $work = realpath( $workspace );
        $plan = self::read_plan( $work );
        $require_fsync = ( $plan['durability'] ?? 'best_effort' ) === 'fsync_required';
        if ( $require_fsync ) {
            self::require_fsync_support( $work );
            self::sync_directory( $root, true );
        }
        if ( $plan['content_root'] !== $root ) {
            throw new RuntimeException( 'Recovery plan is bound to a different destination.' );
        }
        $quarantine_files = $plan['quarantine_files'] ?? array();
        $approval = $quarantine_files ? self::approval_sha256( $quarantine_files,
            $plan['content_root'], $plan['archive_sha256'] ) : null;
        if ( $approval !== ( $plan['quarantine_approval_sha256'] ?? null ) ) {
            throw new RuntimeException( 'Quarantine plan approval fingerprint is invalid.' );
        }
        if ( $quarantine_files && ( ! is_string( $approval_token ) ||
            ! hash_equals( $approval, $approval_token ) ) ) {
            throw new RuntimeException( 'Exact quarantine review approval is required before applying.' );
        }
        if ( $plan['state'] === 'completed' ) {
            return array( 'state' => 'completed', 'files_restored' => 0,
                'untracked_quarantined' => 0, 'untracked_deleted' => 0, 'already_completed' => true );
        }
        $staged = $work . DIRECTORY_SEPARATOR . 'snapshot';
        $originals = $work . DIRECTORY_SEPARATOR . 'originals';
        $quarantine = $work . DIRECTORY_SEPARATOR . 'quarantine';
        $changes = array();
        $quarantine_changes = array();
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
        foreach ( $quarantine_files as $record ) {
            $relative = $record['relative_path'];
            if ( isset( $seen[ $relative ] ) ) {
                throw new RuntimeException( 'Recovery and quarantine plans overlap on a destination path.' );
            }
            $seen[ $relative ] = true;
            $preserved = self::recovery_file( $quarantine, $relative );
            if ( self::file_digest( $preserved ) !== $record['sha256'] ||
                filesize( $preserved ) !== $record['bytes'] ) {
                throw new RuntimeException( 'Quarantined file copy is unavailable or corrupted.' );
            }
            $target = self::target( $root, $relative );
            $current = file_exists( $target ) ? self::file_digest( $target ) : null;
            if ( $current !== null && ( $current !== $record['sha256'] ||
                filesize( $target ) !== $record['bytes'] ) ) {
                throw new RuntimeException( 'Archive-absent destination changed after quarantine review.' );
            }
            $quarantine_changes[] = array( $relative, $target, $record['sha256'] );
        }
        $plan['state'] = 'applying';
        self::write_plan( $work, $plan );
        $restored = 0;
        foreach ( $changes as $change ) {
            list( $relative, $source, $expected, $previous, $mode ) = $change;
            self::mkdir_parents( $root, $relative, 0755, $require_fsync );
            $target = self::target( $root, $relative );
            $current = file_exists( $target ) ? self::file_digest( $target ) : null;
            if ( $current === $expected ) {
                self::sync_directory( dirname( $target ), $require_fsync );
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
                    if ( stream_copy_to_stream( $from, $to ) === false ) {
                        throw new RuntimeException( 'Recovery copy failed.' );
                    }
                    self::sync_stream( $to, $require_fsync );
                } finally {
                    fclose( $from );
                    fclose( $to );
                }
                if ( self::file_digest( $temp ) !== $expected ||
                    self::target( $root, $relative ) !== $target ||
                    ( file_exists( $target ) ? self::file_digest( $target ) : null ) !== $previous ||
                    // Respect permissions tightened after preparation, too.
                    ! @chmod( $temp, $previous !== null ? $mode & self::file_mode( $target ) : $mode ) ) {
                    throw new RuntimeException( 'Recovery destination changed or atomic rename failed.' );
                }
                self::sync_file_path( $temp, $require_fsync );
                if ( ! rename( $temp, $target ) ) {
                    throw new RuntimeException( 'Recovery destination changed or atomic rename failed.' );
                }
                self::sync_directory( dirname( $target ), $require_fsync );
            } finally {
                @unlink( $temp );
            }
            $restored++;
        }
        $quarantined = 0;
        foreach ( $quarantine_changes as $change ) {
            list( $relative, $target, $expected ) = $change;
            $target = self::target( $root, $relative );
            if ( ! file_exists( $target ) ) {
                self::sync_directory( dirname( $target ), $require_fsync );
                continue; // Replay after the file was removed but before the plan was saved.
            }
            if ( self::file_digest( $target ) !== $expected ) {
                throw new RuntimeException( 'Archive-absent destination changed during quarantine apply.' );
            }
            $preserved = self::recovery_file( $quarantine, $relative );
            if ( self::file_digest( $preserved ) !== $expected ||
                filesize( $preserved ) !== filesize( $target ) || ! @unlink( $target ) ) {
                throw new RuntimeException( 'Could not move the approved file into private quarantine.' );
            }
            self::sync_directory( dirname( $target ), $require_fsync );
            $quarantined++;
        }
        $plan['state'] = 'completed';
        self::write_plan( $work, $plan );
        return array( 'state' => 'completed', 'files_restored' => $restored,
            'untracked_quarantined' => $quarantined, 'untracked_deleted' => 0,
            'already_completed' => false );
    }
}
