<?php
require_once __DIR__ . '/../lib/model/class-trasloco-incremental-store.php';

function incremental_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

function incremental_remove_tree( $path ) {
    if ( is_link( $path ) || is_file( $path ) ) { @unlink( $path ); return; }
    if ( ! is_dir( $path ) ) { return; }
    foreach ( new DirectoryIterator( $path ) as $item ) {
        if ( ! $item->isDot() ) { incremental_remove_tree( $item->getPathname() ); }
    }
    @rmdir( $path );
}

$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'trasloco-incremental-' . bin2hex( random_bytes( 8 ) );
$source_dir = $base . DIRECTORY_SEPARATOR . 'source';
$store_dir = $base . DIRECTORY_SEPARATOR . 'store';
$restore_dir = $base . DIRECTORY_SEPARATOR . 'restore';
mkdir( $source_dir, 0700, true ); mkdir( $restore_dir, 0700, true );
$store = new Trasloco_Incremental_Store( $store_dir );
$first_bytes = str_repeat( 'A', Trasloco_Incremental_Store::CHUNK_BYTES ) .
    str_repeat( 'B', Trasloco_Incremental_Store::CHUNK_BYTES );
$second_bytes = str_repeat( 'A', Trasloco_Incremental_Store::CHUNK_BYTES ) .
    str_repeat( 'C', Trasloco_Incremental_Store::CHUNK_BYTES );
$first = $source_dir . '/first.wpress';
$second = $source_dir . '/second.wpress';
file_put_contents( $first, $first_bytes ); file_put_contents( $second, $second_bytes );

try {
    $failed = false;
    try { $store->capture( $first, hash( 'sha256', $second_bytes ) ); } catch ( RuntimeException $error ) { $failed = true; }
    incremental_assert( $failed && is_file( $first ) && ! is_file( $store_dir . '/catalogs/first.wpress.json' ),
        'Capture published a catalog when the source did not match the verified manifest digest.' );
    $one = $store->capture( $first );
    incremental_assert( $one['archive'] === 'first.wpress' && $one['objects'] === 2 && $one['unique_objects'] === 2,
        'First snapshot catalog was not created with two bounded objects.' );
    $two = $store->capture( $second );
    incremental_assert( $two['archive'] === 'second.wpress' && $two['objects'] === 2 && $two['unique_objects'] === 2,
        'Second snapshot catalog was not created with two bounded objects.' );
    incremental_assert( $store->verify( 'first.wpress' )['sha256'] === hash( 'sha256', $first_bytes ) &&
        $store->verify( 'second.wpress' )['sha256'] === hash( 'sha256', $second_bytes ),
        'A snapshot did not verify against its complete archive digest.' );

    $materialized = $restore_dir . '/first.wpress';
    $store->materialize( 'first.wpress', $materialized );
    incremental_assert( hash_file( 'sha256', $materialized ) === hash( 'sha256', $first_bytes ) &&
        ( fileperms( $materialized ) & 0777 ) === 0600,
        'Materialization did not reproduce exact bytes with private permissions.' );

    $catalog_dir = $store_dir . '/catalogs';
    $malformed = $catalog_dir . '/malformed.wpress.json';
    file_put_contents( $malformed, '{not a catalog' );
    $failed = false;
    try { $store->prune( array( 'second.wpress' ) ); } catch ( RuntimeException $error ) { $failed = true; }
    incremental_assert( $failed && is_file( $catalog_dir . '/first.wpress.json' ) && is_file( $catalog_dir . '/second.wpress.json' ),
        'Malformed catalog did not stop pruning before any snapshot deletion.' );
    unlink( $malformed );
    $failed = false;
    try { $store->prune( array() ); } catch ( InvalidArgumentException $error ) { $failed = true; }
    incremental_assert( $failed && is_file( $catalog_dir . '/first.wpress.json' ) && is_file( $catalog_dir . '/second.wpress.json' ),
        'Empty retention policy was allowed to erase every snapshot.' );

    $catalog = json_decode( file_get_contents( $catalog_dir . '/second.wpress.json' ), true );
    $shard = $store_dir . '/objects/' . substr( $catalog['chunks'][1]['sha256'], 0, 2 );
    $abandoned_object = $shard . '/.trasloco-object-abandoned';
    $orphan_digest = hash( 'sha256', 'unreferenced object' );
    $orphan_shard = $store_dir . '/objects/' . substr( $orphan_digest, 0, 2 );
    if ( ! is_dir( $orphan_shard ) ) { mkdir( $orphan_shard, 0700, true ); }
    file_put_contents( $orphan_shard . '/' . $orphan_digest, 'unreferenced object' );
    file_put_contents( $abandoned_object, 'unpublished object bytes' );
    file_put_contents( $catalog_dir . '/.trasloco-catalog-abandoned', 'unpublished catalog bytes' );
    $pruned = $store->prune( array( 'second.wpress' ) );
    incremental_assert( $pruned['removed_snapshots'] === array( 'first.wpress' ) &&
        ! is_file( $catalog_dir . '/first.wpress.json' ) && is_file( $catalog_dir . '/second.wpress.json' ) &&
        ! file_exists( $abandoned_object ) && ! file_exists( $catalog_dir . '/.trasloco-catalog-abandoned' ) &&
        ! file_exists( $orphan_shard . '/' . $orphan_digest ) && in_array( $orphan_digest, $pruned['removed_objects'], true ),
        'Retention did not remove only the unretained catalog and unpublished temporary files.' );
    incremental_assert( $store->verify( 'second.wpress' )['sha256'] === hash( 'sha256', $second_bytes ),
        'Pruning orphaned data still referenced by a retained snapshot.' );
    $objects = array();
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $store_dir . '/objects', FilesystemIterator::SKIP_DOTS ) ) as $entry ) {
        if ( $entry->isFile() ) { $objects[] = $entry->getPathname(); }
    }
    incremental_assert( count( $objects ) === 2, 'Shared and retained unique objects were not pruned by reference.' );

    $catalog = json_decode( file_get_contents( $catalog_dir . '/second.wpress.json' ), true );
    $corrupt_path = $store_dir . '/objects/' . substr( $catalog['chunks'][1]['sha256'], 0, 2 ) . '/' . $catalog['chunks'][1]['sha256'];
    file_put_contents( $corrupt_path, 'corrupt object' );
    $failed = false;
    try { $store->materialize( 'second.wpress', $restore_dir . '/corrupt.wpress' ); } catch ( RuntimeException $error ) { $failed = true; }
    incremental_assert( $failed && ! file_exists( $restore_dir . '/corrupt.wpress' ),
        'Corrupt object data was published as a materialized archive.' );

    $batch_bytes = str_repeat( 'D', Trasloco_Incremental_Store::CHUNK_BYTES ) .
        str_repeat( 'E', Trasloco_Incremental_Store::CHUNK_BYTES ) . 'last partial chunk';
    $batch_archive = $source_dir . '/batch.wpress';
    file_put_contents( $batch_archive, $batch_bytes );
    $batch_chunks = array(
        hash( 'sha256', substr( $batch_bytes, 0, Trasloco_Incremental_Store::CHUNK_BYTES ) ),
        hash( 'sha256', substr( $batch_bytes, Trasloco_Incremental_Store::CHUNK_BYTES, Trasloco_Incremental_Store::CHUNK_BYTES ) ),
        hash( 'sha256', substr( $batch_bytes, 2 * Trasloco_Incremental_Store::CHUNK_BYTES ) ),
    );
    $batch_store = new Trasloco_Incremental_Store( $base . DIRECTORY_SEPARATOR . 'batch-store' );
    $batch_sha = hash( 'sha256', $batch_bytes );
    $manifest_sha = hash( 'sha256', 'verified chunk manifest' );
    $batch = $batch_store->capture_batch( $batch_archive, $batch_sha, $manifest_sha, $batch_chunks, 91, 1 );
    incremental_assert( $batch['status'] === 'pending' && $batch['bytes'] === Trasloco_Incremental_Store::CHUNK_BYTES,
        'Resumable capture did not stop at its configured chunk budget.' );
    $journal = $base . '/batch-store/pending/backup-91.jsonl';
    file_put_contents( $journal, '{"sha256":', FILE_APPEND );
    $batch = $batch_store->capture_batch( $batch_archive, $batch_sha, $manifest_sha, $batch_chunks, 91, 1 );
    incremental_assert( $batch['status'] === 'pending' && $batch['bytes'] === 2 * Trasloco_Incremental_Store::CHUNK_BYTES,
        'Resumable capture did not recover a torn final journal record.' );
    $batch = $batch_store->capture_batch( $batch_archive, $batch_sha, $manifest_sha, $batch_chunks, 91, 1 );
    incremental_assert( $batch['status'] === 'completed' && ! file_exists( $journal ) &&
        $batch_store->verify( 'batch.wpress' )['sha256'] === $batch_sha,
        'Resumable capture did not publish and verify the complete archive.' );
    incremental_assert( $batch_store->capture_batch( $batch_archive, $batch_sha, $manifest_sha,
        $batch_chunks, 91, 1 )['status'] === 'completed',
        'Resuming after catalog publication was not idempotent.' );
    $batch_store->materialize( 'batch.wpress', $restore_dir . '/batch.wpress' );
    incremental_assert( hash_file( 'sha256', $restore_dir . '/batch.wpress' ) === $batch_sha,
        'Resumable capture did not reconstruct the exact source bytes.' );

    echo "Incremental snapshot storage, bounded capture, crash replay, reconstruction, corruption and retention tests passed.\n";
} finally {
    incremental_remove_tree( $base );
}
