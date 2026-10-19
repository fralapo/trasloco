<?php
/** Resumable SHA-256 sidecar generation without rescanning completed prefixes. */
require_once dirname( __DIR__ ) . '/exceptions.php';
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-integrity.php';

function integrity_batch_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$root = sys_get_temp_dir() . '/trasloco-integrity-batch-' . bin2hex( random_bytes( 8 ) );
mkdir( $root, 0700 );
$archive = $root . '/large.wpress';
$job = 'integrity-smoke-1';
$journal = $archive . '.trasloco-manifest-' . $job . '.parts';
try {
    $output = fopen( $archive, 'wb' );
    for ( $index = 0; $index < 11; $index++ ) {
        fwrite( $output, str_repeat( chr( 65 + $index ), 1048576 ) );
    }
    fclose( $output );

    $first = Trasloco_Integrity::create_chunked_step( $archive, $job, null, 1 );
    integrity_batch_assert( $first['complete'] === false && $first['chunks_processed'] === 0 &&
        is_file( $journal ) && filesize( $journal ) === 0,
        'Manifest generation did not persist its private initial checkpoint before scanning.' );
    $checkpoint = json_decode( json_encode( $first['checkpoint'] ), true );

    $second = Trasloco_Integrity::create_chunked_step( $archive, $job, $checkpoint, 1 );
    integrity_batch_assert( $second['complete'] === false && $second['chunks_processed'] === 1 &&
        $second['checkpoint']['offset'] === Trasloco_Integrity::CHUNK_BYTES && filesize( $journal ) === 64,
        'Manifest generation did not stop at its configured chunk batch.' );
    $checkpoint = json_decode( json_encode( $second['checkpoint'] ), true );

    file_put_contents( $journal, 'uncommitted-tail', FILE_APPEND );
    $third = Trasloco_Integrity::create_chunked_step( $archive, $job, $checkpoint, 1 );
    integrity_batch_assert( $third['complete'] === false && $third['checkpoint']['offset'] === 2 * Trasloco_Integrity::CHUNK_BYTES &&
        filesize( $journal ) === 128,
        'Manifest replay did not truncate uncheckpointed journal bytes before continuing.' );
    $checkpoint = json_decode( json_encode( $third['checkpoint'] ), true );

    $tampered = $checkpoint;
    $tampered['offset']++;
    $rejected = false;
    try { Trasloco_Integrity::create_chunked_step( $archive, $job, $tampered, 1 ); }
    catch ( Trasloco_Archive_Exception $expected ) { $rejected = true; }
    integrity_batch_assert( $rejected, 'Manifest generator accepted an inconsistent checkpoint offset.' );
    $tampered = $checkpoint;
    $tampered['whole_state']['words'][0] ^= 1;
    $rejected = false;
    try { Trasloco_Integrity::create_chunked_step( $archive, $job, $tampered, 1 ); }
    catch ( Trasloco_Archive_Exception $expected ) { $rejected = true; }
    integrity_batch_assert( $rejected, 'Manifest generator accepted an altered SHA-256 compression state.' );

    $final = Trasloco_Integrity::create_chunked_step( $archive, $job, $checkpoint, 1 );
    integrity_batch_assert( $final['complete'] === true && is_file( $journal ),
        'Manifest was not atomically published after all bounded scans.' );
    $manifest = Trasloco_Integrity::chunked_manifest( $archive );
    integrity_batch_assert( is_array( $manifest ) && $manifest['size_bytes'] === filesize( $archive ) &&
        $manifest['sha256'] === hash_file( 'sha256', $archive ) && count( $manifest['chunks'] ) === 3 &&
        Trasloco_Integrity::verify( $archive )['status'] === 'verified',
        'Resumable manifest does not match the archive SHA-256 and chunk digests.' );
    integrity_batch_assert( Trasloco_Integrity::cleanup_chunked_journal( $archive, $job ) && ! file_exists( $journal ),
        'Completed job journal cleanup failed.' );

    $changed = $root . '/changed.wpress';
    file_put_contents( $changed, str_repeat( 'X', Trasloco_Integrity::CHUNK_BYTES * 2 ) );
    $changed_job = 'integrity-smoke-2';
    $start = Trasloco_Integrity::create_chunked_step( $changed, $changed_job, null, 1 );
    $cursor = Trasloco_Integrity::create_chunked_step( $changed, $changed_job, $start['checkpoint'], 1 );
    $replacement = $root . '/changed-replacement.wpress';
    file_put_contents( $replacement, str_repeat( 'Y', Trasloco_Integrity::CHUNK_BYTES * 2 ) );
    if ( ! rename( $replacement, $changed ) ) { throw new RuntimeException( 'Could not replace the archive fixture.' ); }
    clearstatcache( true, $changed );
    $rejected = false;
    try { Trasloco_Integrity::create_chunked_step( $changed, $changed_job, $cursor['checkpoint'], 1 ); }
    catch ( Trasloco_Archive_Exception $expected ) { $rejected = true; }
    integrity_batch_assert( $rejected, 'Manifest generation resumed after its archive source changed.' );
    Trasloco_Integrity::cleanup_chunked_journal( $changed, $changed_job );
    echo "Resumable checksum-manifest tests passed.\n";
} finally {
    if ( is_resource( $output ?? null ) ) { fclose( $output ); }
    foreach ( glob( $root . DIRECTORY_SEPARATOR . '*' ) as $path ) {
        if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); }
    }
    rmdir( $root );
}
