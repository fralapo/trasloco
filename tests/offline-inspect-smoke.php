<?php
/** Bounded resumable structural inspection for decrypted .wpress archives. */
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-recovery.php';

function inspect_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

function inspect_header( $name, $size, $folder = '.' ) {
    return pack( 'a255a14a12a4096', $name, (string) $size, '0', $folder );
}

$root = sys_get_temp_dir() . '/trasloco-inspect-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
$archive = $root . '/many-entries.wpress';
try {
    $output = fopen( $archive, 'wb' );
    fwrite( $output, inspect_header( 'package.json', 2 ) . '{}' );
    for ( $index = 0; $index < 600; $index++ ) {
        fwrite( $output, inspect_header( 'entry-' . $index . '.bin', 1, 'wp-content/uploads' ) . 'x' );
    }
    fwrite( $output, str_repeat( "\0", 4377 ) );
    fclose( $output );

    $step = Trasloco_Offline_Recovery::inspect_step( $archive, null, 256 );
    inspect_assert( $step['complete'] === false && $step['entries_processed'] === 256,
        'Structural inspection did not stop at its configured entry batch.' );
    $second = Trasloco_Offline_Recovery::inspect_step( $archive, $step['checkpoint'], 256 );
    inspect_assert( $second['complete'] === false && $second['entries_processed'] === 256,
        'Structural inspection did not resume from its durable cursor.' );
    $third = Trasloco_Offline_Recovery::inspect_step( $archive, $second['checkpoint'], 256 );
    inspect_assert( $third['complete'] === true && $third['entries_processed'] === 89 &&
        $third['entries'] === 601 && isset( $third['files']['package.json'] ),
        'Resumed inspection did not validate all entries and the archive terminator.' );

    $tampered = $step['checkpoint'];
    $tampered['position'] = 1;
    $rejected = false;
    try { Trasloco_Offline_Recovery::inspect_step( $archive, $tampered, 256 ); }
    catch ( RuntimeException $expected ) { $rejected = true; }
    inspect_assert( $rejected, 'Structural inspection accepted a malformed persisted cursor.' );

    file_put_contents( $archive, 'changed', FILE_APPEND );
    $rejected = false;
    try { Trasloco_Offline_Recovery::inspect_step( $archive, $step['checkpoint'], 256 ); }
    catch ( RuntimeException $expected ) { $rejected = true; }
    inspect_assert( $rejected, 'Structural inspection resumed after its source archive changed.' );
    echo "Bounded resumable archive inspection tests passed.\n";
} finally {
    if ( is_resource( $output ?? null ) ) { fclose( $output ); }
    if ( is_file( $archive ) ) { unlink( $archive ); }
    rmdir( $root );
}
