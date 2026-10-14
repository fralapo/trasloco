<?php
/** Local remote-storage transfer checks; no cloud provider or credentials used. */
require dirname( __DIR__ ) . '/exceptions.php';
require dirname( __DIR__ ) . '/lib/model/storage/interface-trasloco-storage-adapter.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-local-storage.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-offsite-transfer.php';
require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-integrity.php';

function offsite_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function offsite_throws( $callback, $message_fragment, $failure_message ) {
    try {
        $callback();
    } catch ( Exception $error ) {
        if ( strpos( $error->getMessage(), $message_fragment ) !== false ) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException( $failure_message );
}

class Trasloco_Fault_After_Put_Storage implements Trasloco_Storage_Adapter {
    private $storage;

    public function __construct( $storage ) { $this->storage = $storage; }
    public function put_stream( $key, $handle ) {
        $this->storage->put_stream( $key, $handle );
        throw new RuntimeException( 'Simulated connection loss after the provider stored the object.' );
    }
    public function open_read( $key, $max_bytes = null ) { return $this->storage->open_read( $key, $max_bytes ); }
    public function exists( $key ) { return $this->storage->exists( $key ); }
    public function delete( $key ) { return $this->storage->delete( $key ); }
    public function enumerate() { return $this->storage->enumerate(); }
}

$root = sys_get_temp_dir() . '/trasloco-offsite-' . bin2hex( random_bytes( 8 ) );
$local_source = $root . '/source';
$local_target = $root . '/target';
$remote_path = $root . '/remote';
foreach ( array( $root, $local_source, $local_target, $remote_path ) as $directory ) {
    if ( ! mkdir( $directory, 0700 ) ) {
        throw new RuntimeException( 'Could not create isolated offsite fixture.' );
    }
}

try {
    $source_archive = $local_source . '/fixture.wpress';
    file_put_contents( $source_archive, "verified archive fixture\n" );
    Trasloco_Integrity::create( $source_archive );
    $remote_store = new Trasloco_Local_Storage( $remote_path );
    $source_transfer = new Trasloco_Offsite_Transfer( $remote_store, $local_source );
    $target_transfer = new Trasloco_Offsite_Transfer( $remote_store, $local_target );

    offsite_throws(
        function () use ( $source_transfer ) { $source_transfer->upload( 'fixture.wpress', false ); },
        'explicit --allow-unencrypted',
        'Plain backup upload did not require explicit consent.'
    );
    offsite_assert( $source_transfer->upload( 'fixture.wpress', true )['status'] === 'uploaded', 'Plain backup upload failed.' );
    offsite_assert( $target_transfer->download( 'fixture.wpress' )['status'] === 'downloaded', 'Plain backup download failed.' );
    offsite_assert( hash_file( 'sha256', $source_archive ) === hash_file( 'sha256', $local_target . '/fixture.wpress' ), 'Downloaded backup bytes differ.' );
    offsite_assert( Trasloco_Integrity::verify( $local_target . '/fixture.wpress' )['status'] === 'verified', 'Downloaded backup manifest did not verify.' );

    offsite_assert( $source_transfer->upload( 'fixture.wpress', true )['status'] === 'already_present', 'Identical remote retry was not idempotent.' );
    copy( $source_archive, $local_source . '/lost-response.wpress' );
    copy( $source_archive . '.manifest.json', $local_source . '/lost-response.wpress.manifest.json' );
    $uncertain_remote = new Trasloco_Fault_After_Put_Storage( $remote_store );
    $uncertain_transfer = new Trasloco_Offsite_Transfer( $uncertain_remote, $local_source );
    offsite_assert( $uncertain_transfer->upload( 'lost-response.wpress', true )['status'] === 'uploaded', 'Committed upload with a lost response was not reconciled.' );
    file_put_contents( $remote_path . '/fixture.wpress', 'different existing bytes' );
    offsite_throws(
        function () use ( $source_transfer ) { $source_transfer->upload( 'fixture.wpress', true ); },
        'different content',
        'Conflicting remote archive was accepted.'
    );

    $encrypted = $local_source . '/encrypted.wpress.enc';
    file_put_contents( $encrypted, "authenticated encrypted fixture\n" );
    Trasloco_Integrity::create( $encrypted );
    offsite_assert( $source_transfer->upload( 'encrypted.wpress.enc', false )['status'] === 'uploaded', 'Encrypted backup upload failed.' );
    offsite_assert( $target_transfer->download( 'encrypted.wpress.enc' )['status'] === 'downloaded', 'Encrypted backup download failed.' );
    offsite_assert( hash_file( 'sha256', $encrypted ) === hash_file( 'sha256', $local_target . '/encrypted.wpress.enc' ), 'Encrypted transfer bytes differ.' );

    $damaged = $remote_path . '/damaged.wpress';
    file_put_contents( $damaged, 'corrupted remote bytes' );
    copy( $source_archive . '.manifest.json', $damaged . '.manifest.json' );
    offsite_throws(
        function () use ( $target_transfer ) { $target_transfer->download( 'damaged.wpress' ); },
        'integrity verification',
        'A damaged remote archive was published locally.'
    );
    offsite_assert( ! file_exists( $local_target . '/damaged.wpress' ), 'Failed transfer left an archive visible in the backup directory.' );

    $occupied = $local_target . '/occupied.wpress';
    file_put_contents( $occupied, 'operator file that must be preserved' );
    copy( $source_archive, $remote_path . '/occupied.wpress' );
    copy( $source_archive . '.manifest.json', $remote_path . '/occupied.wpress.manifest.json' );
    offsite_throws(
        function () use ( $target_transfer ) { $target_transfer->download( 'occupied.wpress' ); },
        'different local backup',
        'Offsite download replaced an existing local file.'
    );
    offsite_assert( file_get_contents( $occupied ) === 'operator file that must be preserved', 'Existing local content changed during download.' );

    $unverified = $local_source . '/unverified.wpress';
    file_put_contents( $unverified, 'missing manifest' );
    offsite_throws(
        function () use ( $source_transfer ) { $source_transfer->upload( 'unverified.wpress', true ); },
        'manifest',
        'An archive without a verified manifest was uploaded.'
    );
    $source_store = new Trasloco_Local_Storage( $local_source );
    offsite_assert( $source_store->exists( 'encrypted.wpress.enc.manifest.json' ), 'Encrypted integrity sidecar is not a valid local storage key.' );
    $oversized_manifest_path = $local_source . '/oversized.wpress.manifest.json';
    $oversized_manifest = fopen( $oversized_manifest_path, 'wb' );
    if ( ! $oversized_manifest || ! ftruncate( $oversized_manifest, Trasloco_Integrity::MAX_MANIFEST_BYTES + 1 ) ) {
        throw new RuntimeException( 'Could not create oversized-manifest fixture.' );
    }
    fclose( $oversized_manifest );
    offsite_throws(
        function () use ( $source_transfer ) { $source_transfer->upload( 'oversized.wpress', true ); },
        'exceeds the allowed size',
        'An oversized sidecar was parsed or uploaded.'
    );
    offsite_throws(
        function () use ( $target_transfer ) { $target_transfer->download( '../escape.wpress' ); },
        'Invalid',
        'Offsite path traversal was accepted.'
    );
    echo "Offsite transfer integrity and collision smoke tests passed.\n";
} finally {
    foreach ( new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $entry ) {
        $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
    }
    rmdir( $root );
}
