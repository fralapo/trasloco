<?php
/** Recovery payload limits and POSIX permission regressions, without WordPress. */
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-file-recovery.php';
function recovery_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function recovery_fixture( $root, $name, $old, $new, $mode = 0600, $package = '{}' ) {
    $base = $root . '/' . $name;
    mkdir( $base . '/wp-content/uploads', 0700, true );
    if ( $old !== null ) {
        file_put_contents( $base . '/wp-content/uploads/file.txt', $old );
        chmod( $base . '/wp-content/uploads/file.txt', $mode );
    }
    $archive = $base . '/snapshot.wpress';
    $data = pack( 'a255a14a12a4096', 'package.json', (string) strlen( $package ), '0', '.' ) . $package;
    $data .= pack( 'a255a14a12a4096', 'file.txt', (string) strlen( $new ), '0', 'uploads' ) . $new;
    file_put_contents( $archive, $data . str_repeat( chr( 0 ), 4377 ) );
    file_put_contents( $archive . '.manifest.json', json_encode( array(
        'manifest_version' => 1, 'algorithm' => 'sha256', 'size_bytes' => filesize( $archive ),
        'sha256' => hash_file( 'sha256', $archive ),
    ) ) );
    return $base;
}
function recovery_reject_budget( $base, $limit ) {
    $rejected = false;
    try {
        Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work', $limit );
    } catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected, 'Original copies exceeded the payload budget without rejection.' );
    recovery_assert( ! file_exists( $base . '/work/plan.json' ), 'Failed preparation published an applicable plan.' );
    recovery_assert( ! file_exists( $base . '/work/originals/uploads/file.txt' ), 'Over-budget original copy was retained.' );
}
$root = sys_get_temp_dir() . '/trasloco-recovery-safety-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
try {
    $base = recovery_fixture( $root, 'large-original', str_repeat( 'X', 1048576 ), 'A' );
    recovery_reject_budget( $base, 16 );
    recovery_assert( filesize( $base . '/wp-content/uploads/file.txt' ) === 1048576, 'Rejected prepare changed live content.' );
    $base = recovery_fixture( $root, 'aggregate-over', 'OLD', 'NEW' );
    recovery_reject_budget( $base, 5 );
    $base = recovery_fixture( $root, 'exact-budget', 'OLD', 'NEW' );
    $report = Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work', 6 );
    recovery_assert( $report['payload_bytes'] === 6, 'Payload report does not count both copies.' );
    recovery_assert( file_get_contents( $base . '/work/originals/uploads/file.txt' ) === 'OLD', 'Original bytes were not retained.' );
    $base = recovery_fixture( $root, 'fsync-required', 'OLD', 'NEW' );
    $strict_supported = true;
    try {
        $report = Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress',
            $base . '/wp-content', $base . '/work', Trasloco_Offline_File_Recovery::MAX_BYTES, false, true );
    } catch ( RuntimeException $e ) {
        $strict_supported = false;
    }
    recovery_assert( $strict_supported === function_exists( 'fsync' ),
        'Strict fsync support did not match the PHP runtime capability.' );
    if ( $strict_supported ) {
        recovery_assert( $report['durability'] === 'fsync_required' &&
            json_decode( file_get_contents( $base . '/work/plan.json' ), true )['durability'] === 'fsync_required',
            'Strict fsync durability was not recorded in the recovery plan.' );
        Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' );
        recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'NEW',
            'Strict fsync recovery did not publish the restored file.' );
    } else {
        recovery_assert( ! file_exists( $base . '/work/plan.json' ) &&
            file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'OLD',
            'Unsupported strict fsync published a plan or changed the live destination.' );
    }
    $base = recovery_fixture( $root, 'empty-original', '', 'NEW' );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work', 3 );
    recovery_assert( filesize( $base . '/work/originals/uploads/file.txt' ) === 0, 'Zero-byte original rejected at exact budget.' );
    foreach ( array( 0600, 0640, 0750 ) as $mode ) {
        $base = recovery_fixture( $root, 'mode-' . $mode, 'OLD', 'NEW', $mode );
        Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work' );
        Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' );
        clearstatcache();
        recovery_assert( ( fileperms( $base . '/wp-content/uploads/file.txt' ) & 0777 ) === $mode, 'Recovery changed existing file permissions.' );
        recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'NEW', 'Recovery did not replace content.' );
    }
    $base = recovery_fixture( $root, 'legacy-plan', 'OLD', 'NEW', 0600 );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work' );
    $path = $base . '/work/plan.json';
    $plan = json_decode( file_get_contents( $path ), true );
    unset( $plan['files'][0]['previous_mode'] );
    file_put_contents( $path, json_encode( $plan ) );
    Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' );
    clearstatcache();
    recovery_assert( ( fileperms( $base . '/wp-content/uploads/file.txt' ) & 0777 ) === 0600, 'Legacy plan widened permissions.' );
    $base = recovery_fixture( $root, 'mode-tightened', 'OLD', 'NEW', 0644 );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work' );
    chmod( $base . '/wp-content/uploads/file.txt', 0600 );
    clearstatcache();
    Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' );
    clearstatcache();
    recovery_assert( ( fileperms( $base . '/wp-content/uploads/file.txt' ) & 0777 ) === 0600, 'Recovery widened permissions tightened after planning.' );
    $base = recovery_fixture( $root, 'missing', null, 'NEW' );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work', 3 );
    Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' );
    clearstatcache();
    recovery_assert( ( fileperms( $base . '/wp-content/uploads/file.txt' ) & 0777 ) === 0644, 'Missing file did not receive default permissions.' );
    $base = recovery_fixture( $root, 'concurrent', 'OLD', 'NEW' );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work' );
    $lock = fopen( $base . '/work/.apply.lock', 'c+b' );
    recovery_assert( $lock !== false && flock( $lock, LOCK_EX | LOCK_NB ), 'Could not arrange competing recovery lock.' );
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/tools/recovery-files.php' ) .
        ' apply ' . escapeshellarg( $base . '/work' ) . ' ' . escapeshellarg( $base . '/wp-content' ) .
        ' --site-offline-confirmed 2>&1';
    $output = array();
    $status = 0;
    exec( $command, $output, $status );
    flock( $lock, LOCK_UN );
    fclose( $lock );
    recovery_assert( $status !== 0 && strpos( implode( "\n", $output ), 'Another recovery process' ) !== false,
        'Concurrent recovery was not rejected with a clear diagnostic.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'OLD',
        'A rejected concurrent recovery modified the site.' );
    recovery_assert( json_decode( file_get_contents( $base . '/work/plan.json' ), true )['state'] === 'prepared',
        'A rejected concurrent recovery changed the plan state.' );
    $applied = Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' );
    recovery_assert( $applied['state'] === 'completed' &&
        file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'NEW',
        'Recovery did not resume after competing process released lock.' );
    $base = recovery_fixture( $root, 'hardlink-lock', 'OLD', 'NEW' );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work' );
    $lock_target = $base . '/outside-lock-target';
    file_put_contents( $lock_target, 'Do not chmod through a workspace lock link.' );
    chmod( $lock_target, 0644 );
    recovery_assert( link( $lock_target, $base . '/work/.apply.lock' ), 'Could not arrange lock hard-link fixture.' );
    $rejected = false;
    try { Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' ); }
    catch ( RuntimeException $e ) { $rejected = true; }
    clearstatcache();
    recovery_assert( $rejected, 'Hard-linked lock file was accepted.' );
    recovery_assert( ( fileperms( $lock_target ) & 0777 ) === 0644, 'Lock validation changed permissions through a hard link.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'OLD', 'Unsafe lock changed live content.' );
    $base = recovery_fixture( $root, 'invalid-mode', 'OLD', 'NEW' );
    Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work' );
    $path = $base . '/work/plan.json';
    $plan = json_decode( file_get_contents( $path ), true );
    $plan['files'][0]['previous_mode'] = 04777;
    file_put_contents( $path, json_encode( $plan ) );
    $rejected = false;
    try { Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content' ); }
    catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected, 'Special permission bits accepted from plan.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'OLD', 'Invalid mode changed live content.' );

    $base = recovery_fixture( $root, 'quarantine', 'OLD', 'NEW' );
    mkdir( $base . '/wp-content/uploads/cache', 0700 );
    file_put_contents( $base . '/wp-content/uploads/cache/generated.txt', 'Generated after snapshot' );
    mkdir( $base . '/wp-content/plugins/extra-plugin', 0700, true );
    file_put_contents( $base . '/wp-content/plugins/extra-plugin/plugin.php', '<?php // newer plugin' );
    mkdir( $base . '/wp-content/plugins/trasloco', 0700, true );
    file_put_contents( $base . '/wp-content/plugins/trasloco/trasloco.php', '<?php // keep the recovery plugin' );
    $prepared = Trasloco_Offline_File_Recovery::prepare(
        $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work',
        Trasloco_Offline_File_Recovery::MAX_BYTES, true
    );
    recovery_assert( $prepared['quarantine_candidates'] === 2, 'Untracked files were not all planned for quarantine.' );
    recovery_assert( preg_match( '/^[a-f0-9]{64}$/D', $prepared['approval_sha256'] ), 'Approval fingerprint is missing.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/cache/generated.txt' ) === 'Generated after snapshot', 'Preparation changed a live untracked file.' );
    recovery_assert( file_get_contents( $base . '/work/quarantine/uploads/cache/generated.txt' ) === 'Generated after snapshot', 'Preparation did not preserve an untracked file privately.' );
    $review = Trasloco_Offline_File_Recovery::review( $base . '/work' );
    recovery_assert( count( $review['quarantine_files'] ) === 2 &&
        $review['approval_sha256'] === $prepared['approval_sha256'] &&
        $review['content_root'] === realpath( $base . '/wp-content' ) &&
        $review['archive_sha256'] === hash_file( 'sha256', $base . '/snapshot.wpress' ),
        'Review output differs from the prepared approval set or target.' );
    $rejected = false;
    try { Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content', str_repeat( '0', 64 ) ); }
    catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected, 'Quarantine apply accepted a stale approval fingerprint.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'OLD', 'Invalid approval changed a snapshot file.' );
    $applied = Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content', $prepared['approval_sha256'] );
    recovery_assert( $applied['state'] === 'completed' && $applied['untracked_quarantined'] === 2,
        'Approved recovery did not quarantine all archive-absent files.' );
    recovery_assert( ! file_exists( $base . '/wp-content/uploads/cache/generated.txt' ) &&
        ! file_exists( $base . '/wp-content/plugins/extra-plugin/plugin.php' ), 'Approved files remain in the live content tree.' );
    recovery_assert( file_exists( $base . '/wp-content/plugins/trasloco/trasloco.php' ),
        'The plugin omitted by design was included as an archive-absent candidate.' );
    recovery_assert( file_get_contents( $base . '/work/quarantine/plugins/extra-plugin/plugin.php' ) === '<?php // newer plugin',
        'Quarantined plugin bytes were not retained privately.' );
    $restored = Trasloco_Offline_File_Recovery::restore_quarantine(
        $base . '/work', $base . '/wp-content', $prepared['approval_sha256']
    );
    recovery_assert( $restored['files_restored'] === 2 &&
        file_get_contents( $base . '/wp-content/uploads/cache/generated.txt' ) === 'Generated after snapshot',
        'Quarantined files could not be restored.' );
    file_put_contents( $base . '/wp-content/uploads/cache/generated.txt', 'Newer after rollback' );
    $rejected = false;
    try { Trasloco_Offline_File_Recovery::restore_quarantine(
        $base . '/work', $base . '/wp-content', $prepared['approval_sha256']
    ); } catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected && file_get_contents( $base . '/wp-content/uploads/cache/generated.txt' ) === 'Newer after rollback',
        'Quarantine restore overwrote newer destination data.' );

    $base = recovery_fixture( $root, 'quarantine-export-exclusion', null, 'NEW', 0600,
        json_encode( array( 'ContentExcludedPaths' => array( 'uploads/cache' ) ) ) );
    mkdir( $base . '/wp-content/uploads/cache', 0700 );
    file_put_contents( $base . '/wp-content/uploads/cache/generated.txt', 'Excluded by export filter' );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'Not excluded by export filter' );
    $prepared = Trasloco_Offline_File_Recovery::prepare(
        $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work',
        Trasloco_Offline_File_Recovery::MAX_BYTES, true
    );
    recovery_assert( $prepared['quarantine_candidates'] === 1 &&
        file_exists( $base . '/wp-content/uploads/cache/generated.txt' ) &&
        file_exists( $base . '/work/quarantine/uploads/extra.txt' ),
        'Recovery did not honor a content path excluded by the export filter.' );

    $base = recovery_fixture( $root, 'quarantine-unsafe-export-exclusion', null, 'NEW', 0600,
        json_encode( array( 'ContentExcludedPaths' => array( 'uploads/../plugins' ) ) ) );
    $rejected = false;
    try {
        Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content',
            $base . '/work', Trasloco_Offline_File_Recovery::MAX_BYTES, true );
    } catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected && ! file_exists( $base . '/work/plan.json' ),
        'Recovery accepted an unsafe path from package exclusion metadata.' );

    $base = recovery_fixture( $root, 'quarantine-changed', 'OLD', 'NEW' );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'Before plan' );
    $prepared = Trasloco_Offline_File_Recovery::prepare(
        $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work',
        Trasloco_Offline_File_Recovery::MAX_BYTES, true
    );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'Changed after plan' );
    $rejected = false;
    try { Trasloco_Offline_File_Recovery::apply( $base . '/work', $base . '/wp-content', $prepared['approval_sha256'] ); }
    catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected, 'Changed archive-absent destination file was quarantined.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'OLD',
        'Snapshot files were changed before candidate preflight completed.' );
    recovery_assert( file_get_contents( $base . '/wp-content/uploads/extra.txt' ) === 'Changed after plan',
        'A post-plan file change was overwritten or deleted.' );

    $base = recovery_fixture( $root, 'quarantine-budget', null, 'NEW' );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'Four' );
    $rejected = false;
    try {
        Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content',
            $base . '/work', 6, true );
    } catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected && ! file_exists( $base . '/work/plan.json' ) &&
        file_get_contents( $base . '/wp-content/uploads/extra.txt' ) === 'Four',
        'Over-budget quarantine published a plan or changed the destination.' );

    $base = recovery_fixture( $root, 'quarantine-symlink', null, 'NEW' );
    $outside = $base . '/outside';
    mkdir( $outside );
    file_put_contents( $outside . '/secret.txt', 'Must not be followed.' );
    symlink( $outside, $base . '/wp-content/plugins' );
    $rejected = false;
    try {
        Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content',
            $base . '/work', Trasloco_Offline_File_Recovery::MAX_BYTES, true );
    } catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected && ! file_exists( $base . '/work/plan.json' ) &&
        file_get_contents( $outside . '/secret.txt' ) === 'Must not be followed.',
        'Quarantine followed a linked content group or published an incomplete inventory.' );

    $base = recovery_fixture( $root, 'quarantine-partial-archive', null, 'NEW', 0600,
        json_encode( array( 'NoMedia' => true ) ) );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'Intentionally not archived' );
    $rejected = false;
    try {
        Trasloco_Offline_File_Recovery::prepare( $base . '/snapshot.wpress', $base . '/wp-content',
            $base . '/work', Trasloco_Offline_File_Recovery::MAX_BYTES, true );
    } catch ( RuntimeException $e ) { $rejected = true; }
    recovery_assert( $rejected && ! file_exists( $base . '/work/plan.json' ) &&
        file_get_contents( $base . '/wp-content/uploads/extra.txt' ) === 'Intentionally not archived',
        'Archive-absent quarantine accepted a deliberately media-excluded backup.' );

    $base = recovery_fixture( $root, 'quarantine-resume', null, 'NEW' );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'Safe after interruption' );
    $prepared = Trasloco_Offline_File_Recovery::prepare(
        $base . '/snapshot.wpress', $base . '/wp-content', $base . '/work',
        Trasloco_Offline_File_Recovery::MAX_BYTES, true
    );
    $plan_path = $base . '/work/plan.json';
    $plan = json_decode( file_get_contents( $plan_path ), true );
    $plan['state'] = 'applying';
    file_put_contents( $plan_path, json_encode( $plan ) );
    unlink( $base . '/wp-content/uploads/extra.txt' );
    $resumed = Trasloco_Offline_File_Recovery::apply(
        $base . '/work', $base . '/wp-content', $prepared['approval_sha256']
    );
    recovery_assert( $resumed['state'] === 'completed' &&
        file_get_contents( $base . '/wp-content/uploads/file.txt' ) === 'NEW' &&
        file_get_contents( $base . '/work/quarantine/uploads/extra.txt' ) === 'Safe after interruption',
        'Recovery did not resume after interruption during candidate quarantine.' );

    $base = recovery_fixture( $root, 'quarantine-cli', null, 'NEW' );
    file_put_contents( $base . '/wp-content/uploads/extra.txt', 'CLI quarantine' );
    $tool = dirname( __DIR__ ) . '/tools/recovery-files.php';
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tool ) .
        ' prepare ' . escapeshellarg( $base . '/snapshot.wpress' ) .
        ' ' . escapeshellarg( $base . '/wp-content' ) . ' ' . escapeshellarg( $base . '/work' ) .
        ' --include-untracked 2>&1';
    $output = array();
    exec( $command, $output, $status );
    recovery_assert( $status === 0, 'CLI quarantine preparation failed: ' . implode( "\n", $output ) );
    $prepared = json_decode( implode( "\n", $output ), true );
    recovery_assert( is_array( $prepared ) && $prepared['quarantine_candidates'] === 1,
        'CLI preparation did not report the quarantine candidate: ' . implode( "\n", $output ) );
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tool ) .
        ' review ' . escapeshellarg( $base . '/work' ) . ' 2>&1';
    $output = array();
    exec( $command, $output, $status );
    $review = json_decode( implode( "\n", $output ), true );
    recovery_assert( $status === 0 && count( $review['quarantine_files'] ) === 1,
        'CLI review did not print the complete quarantine list.' );
    $approval = $prepared['approval_sha256'];
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tool ) .
        ' apply ' . escapeshellarg( $base . '/work' ) . ' ' . escapeshellarg( $base . '/wp-content' ) .
        ' --site-offline-confirmed --approve-untracked=' . escapeshellarg( $approval ) . ' 2>&1';
    $output = array();
    exec( $command, $output, $status );
    recovery_assert( $status === 0 && ! file_exists( $base . '/wp-content/uploads/extra.txt' ),
        'CLI approved quarantine apply failed: ' . implode( "\n", $output ) );
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tool ) .
        ' restore-quarantine ' . escapeshellarg( $base . '/work' ) . ' ' .
        escapeshellarg( $base . '/wp-content' ) . ' --approve-untracked=' .
        escapeshellarg( $approval ) . ' 2>&1';
    $output = array();
    exec( $command, $output, $status );
    recovery_assert( $status === 0 && file_get_contents( $base . '/wp-content/uploads/extra.txt' ) === 'CLI quarantine',
        'CLI failed to restore the quarantined file: ' . implode( "\n", $output ) );
    echo "Offline file recovery safety tests passed.\n";
} finally {
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) { rmdir( $file->getPathname() ); }
        else { unlink( $file->getPathname() ); }
    }
    rmdir( $root );
}
