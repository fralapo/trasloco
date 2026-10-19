<?php
/** Remote incremental transfer fixtures; no real provider or credentials used. */
require_once dirname( __DIR__ ) . '/lib/model/storage/interface-trasloco-storage-adapter.php';
require_once dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-local-storage.php';
require_once dirname( __DIR__ ) . '/lib/model/class-trasloco-incremental-store.php';
require_once dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-incremental-transfer.php';

function incremental_transfer_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function incremental_transfer_remove( $path ) {
    if ( is_link( $path ) || is_file( $path ) ) { @unlink( $path ); return; }
    if ( ! is_dir( $path ) ) { return; }
    foreach ( new DirectoryIterator( $path ) as $entry ) { if ( ! $entry->isDot() ) { incremental_transfer_remove( $entry->getPathname() ); } }
    @rmdir( $path );
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-incremental-transfer-' . bin2hex( random_bytes( 8 ) );
$source = $root . DIRECTORY_SEPARATOR . 'source'; $remote = $root . DIRECTORY_SEPARATOR . 'remote'; $target = $root . DIRECTORY_SEPARATOR . 'target';
mkdir( $source, 0700, true ); mkdir( $remote, 0700, true ); mkdir( $target, 0700, true );
try {
    $bytes = str_repeat( 'remote snapshot fixture A', 190000 ) . str_repeat( 'remote snapshot fixture B', 90000 );
    file_put_contents( $source . DIRECTORY_SEPARATOR . 'site.wpress', $bytes );
    $source_store = new Trasloco_Incremental_Store( $source . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
    $source_store->capture( $source . DIRECTORY_SEPARATOR . 'site.wpress' );
    $remote_adapter = new Trasloco_Local_Storage( $remote );
    $uploader = new Trasloco_Incremental_Transfer( $remote_adapter, $source_store );
    $first = $uploader->upload( 'site.wpress' );
    $again = $uploader->upload( 'site.wpress' );
    incremental_transfer_assert( $first['status'] === 'uploaded' && $first['objects_uploaded'] === 2 &&
        $again['status'] === 'already_present', 'Incremental upload did not publish immutable objects and catalog idempotently.' );
    $keys = $remote_adapter->enumerate();
    incremental_transfer_assert( count( $keys ) === 3 && strpos( $keys[0], 'trc-' ) === 0 && strpos( $keys[1], 'tro-' ) === 0,
        'Remote object or catalog key was not safely constrained.' );

    $second_bytes = substr( $bytes, 0, Trasloco_Incremental_Store::CHUNK_BYTES ) . str_repeat( 'changed remote tail', 123456 );
    file_put_contents( $source . DIRECTORY_SEPARATOR . 'site-next.wpress', $second_bytes );
    $source_store->capture( $source . DIRECTORY_SEPARATOR . 'site-next.wpress' );
    $delta = $uploader->upload( 'site-next.wpress' );
    incremental_transfer_assert( $delta['objects_uploaded'] === 1 &&
        $delta['bytes_uploaded'] < strlen( $second_bytes ), 'A second snapshot did not reuse its already uploaded immutable object.' );
    $keys = $remote_adapter->enumerate();
    incremental_transfer_assert( count( $keys ) === 5, 'Shared remote chunks were duplicated or a snapshot catalog was omitted.' );
    $first_catalog = array_values( array_filter( $keys, function ( $key ) { return strpos( $key, 'trc-' ) === 0; } ) )[0];
    $catalog_data = json_decode( file_get_contents( $remote . DIRECTORY_SEPARATOR . $first_catalog ), true );
    file_put_contents( $remote . DIRECTORY_SEPARATOR . $first_catalog, json_encode( $catalog_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

    $target_store = new Trasloco_Incremental_Store( $target . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
    $downloader = new Trasloco_Incremental_Transfer( $remote_adapter, $target_store );
    $result = $downloader->download( 'site.wpress' );
    $materialized = $target . DIRECTORY_SEPARATOR . 'restored.wpress';
    $target_store->materialize( 'site.wpress', $materialized );
    incremental_transfer_assert( $result['status'] === 'downloaded' && hash_file( 'sha256', $materialized ) === hash( 'sha256', $bytes ),
        'Remote snapshot did not reconstruct the exact verified archive bytes.' );
    incremental_transfer_assert( $downloader->download( 'site.wpress' )['status'] === 'already_present',
        'Repeated remote catalog import was not idempotent.' );

    $bad_remote = $root . DIRECTORY_SEPARATOR . 'bad-remote'; mkdir( $bad_remote, 0700, true );
    $bad_adapter = new Trasloco_Local_Storage( $bad_remote );
    foreach ( $keys as $key ) {
        $in = $remote_adapter->open_read( $key );
        try { $bad_adapter->put_stream( $key, $in ); } finally { fclose( $in ); }
    }
    $object_key = $keys[1]; file_put_contents( $bad_remote . DIRECTORY_SEPARATOR . $object_key, 'tampered object' );
    $bad_target = $root . DIRECTORY_SEPARATOR . 'bad-target'; mkdir( $bad_target, 0700, true );
    $bad_store = new Trasloco_Incremental_Store( $bad_target . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
    $failed = false;
    try { ( new Trasloco_Incremental_Transfer( $bad_adapter, $bad_store ) )->download( 'site.wpress' ); }
    catch ( RuntimeException $error ) { $failed = true; }
    incremental_transfer_assert( $failed && ! is_file( $bad_target . DIRECTORY_SEPARATOR . '.trasloco-incremental/catalogs/site.wpress.json' ),
        'Corrupted remote object resulted in a published local snapshot catalog.' );
    echo "Remote incremental object and catalog transfer passed.\n";
} finally { incremental_transfer_remove( $root ); }
