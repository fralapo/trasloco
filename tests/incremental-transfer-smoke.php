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
    $interrupted = false;
    try { $uploader->upload( 'site.wpress', function ( $done, $total, $bytes_done, $phase ) { return $phase !== 'uploading'; } ); }
    catch ( RuntimeException $error ) { $interrupted = true; }
    incremental_transfer_assert( $interrupted && count( $remote_adapter->enumerate() ) === 1,
        'Interrupted incremental upload did not stop before publishing its catalog.' );
    $upload_progress = array();
    $first = $uploader->upload( 'site.wpress', function ( $done, $total, $bytes_done, $phase ) use ( &$upload_progress ) {
        if ( $phase === 'uploading' ) { $upload_progress[] = array( $done, $total, $bytes_done ); }
        return true;
    } );
    $again = $uploader->upload( 'site.wpress' );
    incremental_transfer_assert( $first['status'] === 'uploaded' && $first['objects_uploaded'] === 1 &&
        $again['status'] === 'already_present' && count( $upload_progress ) === 2 &&
        $upload_progress[1][0] === 2 && $upload_progress[1][1] === 2,
        'Incremental upload did not publish immutable objects and catalog idempotently with per-object progress.' );
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
    $first_catalog = 'trc-' . hash( 'sha256', 'site.wpress' ) . '.json';
    $catalog_data = json_decode( file_get_contents( $remote . DIRECTORY_SEPARATOR . $first_catalog ), true );
    file_put_contents( $remote . DIRECTORY_SEPARATOR . $first_catalog, json_encode( $catalog_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

    $target_store = new Trasloco_Incremental_Store( $target . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
    $downloader = new Trasloco_Incremental_Transfer( $remote_adapter, $target_store );
    $download_interrupted = false;
    try { $downloader->download( 'site.wpress', function () { return false; } ); }
    catch ( RuntimeException $error ) { $download_interrupted = true; }
    incremental_transfer_assert( $download_interrupted && ! is_file( $target . DIRECTORY_SEPARATOR . '.trasloco-incremental/catalogs/site.wpress.json' ),
        'Interrupted incremental download published a catalog before all objects were accepted.' );
    $verify_interrupted = false;
    try { $downloader->download( 'site.wpress', function ( $done, $total, $bytes_done, $phase ) { return $phase !== 'verifying'; } ); }
    catch ( RuntimeException $error ) { $verify_interrupted = true; }
    incremental_transfer_assert( $verify_interrupted && ! is_file( $target . DIRECTORY_SEPARATOR . '.trasloco-incremental/catalogs/site.wpress.json' ),
        'Interrupted whole-archive verification published a local snapshot catalog.' );
    $download_progress = array();
    $result = $downloader->download( 'site.wpress', function ( $done, $total, $bytes_done, $phase ) use ( &$download_progress ) {
        if ( $phase === 'downloading' ) { $download_progress[] = array( $done, $total, $bytes_done ); }
        return true;
    } );
    $materialized = $target . DIRECTORY_SEPARATOR . 'restored.wpress';
    $target_store->materialize( 'site.wpress', $materialized );
    incremental_transfer_assert( $result['status'] === 'downloaded' && count( $download_progress ) === 2 &&
        $download_progress[1][0] === 2 && $download_progress[1][1] === 2 &&
        hash_file( 'sha256', $materialized ) === hash( 'sha256', $bytes ),
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
    $bad_catalog_remote = $root . DIRECTORY_SEPARATOR . 'bad-catalog-remote'; mkdir( $bad_catalog_remote, 0700, true );
    $bad_catalog_adapter = new Trasloco_Local_Storage( $bad_catalog_remote );
    foreach ( $keys as $key ) {
        $in = $remote_adapter->open_read( $key );
        try { $bad_catalog_adapter->put_stream( $key, $in ); } finally { fclose( $in ); }
    }
    $catalog_path = $bad_catalog_remote . DIRECTORY_SEPARATOR . $first_catalog;
    $bad_catalog = json_decode( file_get_contents( $catalog_path ), true );
    $bad_catalog['sha256'] = str_repeat( '0', 64 );
    file_put_contents( $catalog_path, json_encode( $bad_catalog, JSON_UNESCAPED_SLASHES ) );
    $bad_catalog_target = $root . DIRECTORY_SEPARATOR . 'bad-catalog-target'; mkdir( $bad_catalog_target, 0700, true );
    $bad_catalog_store = new Trasloco_Incremental_Store( $bad_catalog_target . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
    $failed = false;
    try { ( new Trasloco_Incremental_Transfer( $bad_catalog_adapter, $bad_catalog_store ) )->download( 'site.wpress' ); }
    catch ( RuntimeException $error ) { $failed = true; }
    incremental_transfer_assert( $failed && ! is_file( $bad_catalog_target . DIRECTORY_SEPARATOR . '.trasloco-incremental/catalogs/site.wpress.json' ),
        'A catalog with a false whole-archive digest became visible before reconstruction verification.' );
    echo "Remote incremental object and catalog transfer passed.\n";
} finally { incremental_transfer_remove( $root ); }
