<?php
/** Recovery payload limits and POSIX permission regressions, without WordPress. */
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-file-recovery.php';
function recovery_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function recovery_fixture( $root, $name, $old, $new, $mode = 0600 ) {
    $base = $root . '/' . $name;
    mkdir( $base . '/wp-content/uploads', 0700, true );
    if ( $old !== null ) {
        file_put_contents( $base . '/wp-content/uploads/file.txt', $old );
        chmod( $base . '/wp-content/uploads/file.txt', $mode );
    }
    $archive = $base . '/snapshot.wpress';
    $data = pack( 'a255a14a12a4096', 'package.json', '2', '0', '.' ) . '{}';
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
    echo "Offline file recovery safety tests passed.\n";
} finally {
    $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) { rmdir( $file->getPathname() ); }
        else { unlink( $file->getPathname() ); }
    }
    rmdir( $root );
}
