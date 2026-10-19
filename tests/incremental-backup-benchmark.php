<?php
/**
 * Repeated real WordPress export fixture for measuring incremental savings.
 * Run through `wp eval-file` with TRASLOCO_INCREMENTAL_BENCH_MODE and
 * TRASLOCO_INCREMENTAL_BENCH_STATE set by tests/integration.sh.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'TRASLOCO_BACKUPS_PATH' ) ) {
    throw new RuntimeException( 'This benchmark must run inside the WordPress integration fixture.' );
}

require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';

$mode = getenv( 'TRASLOCO_INCREMENTAL_BENCH_MODE' );
$state_path = getenv( 'TRASLOCO_INCREMENTAL_BENCH_STATE' );
if ( ! is_string( $state_path ) || $state_path === '' ) {
    throw new RuntimeException( 'Incremental benchmark state path is required.' );
}

$store = new Trasloco_Incremental_Store(
    TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.trasloco-incremental'
);
$fixture_dir = WP_CONTENT_DIR . '/uploads/trasloco-incremental-benchmark';
$stable_path = $fixture_dir . '/a-stable-media.bin';
$tail_path = $fixture_dir . '/z-new-media.bin';

if ( $mode === 'prepare' ) {
    if ( ! wp_mkdir_p( $fixture_dir ) || is_link( $fixture_dir ) || is_file( $stable_path ) || is_link( $stable_path ) ||
        is_file( $tail_path ) || is_link( $tail_path ) ) {
        throw new RuntimeException( 'Could not safely create the incremental benchmark fixture.' );
    }
    if ( file_put_contents( $stable_path, random_bytes( 16 * 1024 * 1024 ) ) !== 16 * 1024 * 1024 ) {
        throw new RuntimeException( 'Could not write the stable media benchmark fixture.' );
    }
    if ( file_put_contents( $tail_path, random_bytes( 512 * 1024 ) ) !== 512 * 1024 ) {
        throw new RuntimeException( 'Could not write the mutable media benchmark fixture.' );
    }
    $state = array(
        'archives_before' => array_column( $store->list_snapshots(), 'archive' ),
        'stats_before' => $store->storage_stats(),
    );
    if ( file_put_contents( $state_path, wp_json_encode( $state ) ) === false ) {
        throw new RuntimeException( 'Could not save incremental benchmark baseline.' );
    }
    echo "Prepared 16 MiB stable media fixture.\n";
    return;
}

$state = json_decode( (string) @file_get_contents( $state_path ), true );
if ( ! is_array( $state ) || ! isset( $state['archives_before'], $state['stats_before'] ) ) {
    throw new RuntimeException( 'Incremental benchmark baseline is invalid.' );
}

if ( $mode === 'append' ) {
    if ( ! is_file( $tail_path ) || is_link( $tail_path ) || filesize( $tail_path ) !== 512 * 1024 ||
        file_put_contents( $tail_path, random_bytes( 512 * 1024 ) ) !== 512 * 1024 ) {
        throw new RuntimeException( 'Could not replace the mutable media benchmark fixture.' );
    }
    echo "Changed 512 KiB of media while preserving the archive file list and sizes.\n";
    return;
}

if ( $mode === 'names' ) {
    $before = array_fill_keys( $state['archives_before'], true );
    $names = array();
    foreach ( $store->list_snapshots() as $snapshot ) {
        if ( ! isset( $before[ $snapshot['archive'] ] ) ) {
            $names[] = $snapshot['archive'];
        }
    }
    sort( $names, SORT_STRING );
    if ( count( $names ) !== 2 ) {
        throw new RuntimeException( 'Repeated backup benchmark expected exactly two new snapshots.' );
    }

    $catalog_dir = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.trasloco-incremental' . DIRECTORY_SEPARATOR . 'catalogs';
    $catalogs = array();
    foreach ( $names as $name ) {
        $verified = $store->verify( $name );
        $catalog = json_decode( (string) @file_get_contents( $catalog_dir . DIRECTORY_SEPARATOR . $name . '.json' ), true );
        if ( ! is_array( $catalog ) || $verified['sha256'] !== $catalog['sha256'] ) {
            throw new RuntimeException( 'A repeated backup snapshot did not match its verified catalog.' );
        }
        $catalogs[] = $catalog;
    }
    if ( hash_equals( $catalogs[0]['sha256'], $catalogs[1]['sha256'] ) ) {
        throw new RuntimeException( 'The repeated backup fixture did not change between exports.' );
    }

    $second_chunks = array();
    foreach ( $catalogs[1]['chunks'] as $chunk ) {
        $second_chunks[ $chunk['sha256'] ] = $chunk['size_bytes'];
    }
    $shared_bytes = 0;
    $shared_objects = 0;
    foreach ( $catalogs[0]['chunks'] as $chunk ) {
        if ( isset( $second_chunks[ $chunk['sha256'] ] ) &&
            $second_chunks[ $chunk['sha256'] ] === $chunk['size_bytes'] ) {
            $shared_bytes += $chunk['size_bytes'];
            $shared_objects++;
        }
    }
    if ( $shared_bytes < 3 * Trasloco_Incremental_Store::CHUNK_BYTES ) {
        throw new RuntimeException( sprintf( 'Expected at least 12 MiB of shared archive chunks, measured %d bytes.', $shared_bytes ) );
    }

    $state['archives'] = $names;
    $state['shared_bytes'] = $shared_bytes;
    $state['shared_objects'] = $shared_objects;
    if ( file_put_contents( $state_path, wp_json_encode( $state ) ) === false ) {
        throw new RuntimeException( 'Could not save repeated backup benchmark results.' );
    }
    foreach ( $names as $name ) { echo $name . "\n"; }
    return;
}

if ( $mode === 'finish' ) {
    if ( empty( $state['archives'] ) || count( $state['archives'] ) !== 2 ) {
        throw new RuntimeException( 'Repeated backup names are missing from benchmark state.' );
    }
    foreach ( $state['archives'] as $name ) {
        if ( is_file( TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $name ) ||
            is_file( TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $name . '.manifest.json' ) ) {
            throw new RuntimeException( 'Verified benchmark compaction left a full archive or manifest in place.' );
        }
    }
    $after = $store->storage_stats();
    $logical_bytes = $after['logical_bytes'] - $state['stats_before']['logical_bytes'];
    $physical_bytes = $after['physical_bytes'] - $state['stats_before']['physical_bytes'];
    $saved_bytes = $logical_bytes - $physical_bytes;
    if ( $logical_bytes <= 0 || $saved_bytes <= 0 ) {
        throw new RuntimeException( sprintf( 'Repeated real backups did not produce net storage savings: logical=%d physical=%d.',
            $logical_bytes, $physical_bytes ) );
    }
    $percentage = round( ( $saved_bytes / $logical_bytes ) * 100, 1 );
    printf( "Repeated WordPress backup benchmark passed: 2 archives, %d logical bytes, %d shared bytes in %d chunks, %d incremental physical bytes, %d net bytes saved (%.1f%%).\n",
        $logical_bytes, $state['shared_bytes'], $state['shared_objects'], $physical_bytes, $saved_bytes, $percentage );
    return;
}

throw new RuntimeException( 'Unsupported incremental benchmark mode.' );
