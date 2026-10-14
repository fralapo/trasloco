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
    public function open_read_range( $key, $offset, $length, $total_bytes ) {
        return $this->storage->open_read_range( $key, $offset, $length, $total_bytes );
    }
    public function exists( $key ) { return $this->storage->exists( $key ); }
    public function delete( $key ) { return $this->storage->delete( $key ); }
    public function enumerate() { return $this->storage->enumerate(); }
}

class Trasloco_Transient_Once_Storage implements Trasloco_Storage_Adapter {
    private $storage;
    public $fail_put = true;
    public $fail_read = true;

    public function __construct( $storage ) { $this->storage = $storage; }
    public function put_stream( $key, $handle ) {
        if ( $this->fail_put ) {
            $this->fail_put = false;
            throw new Trasloco_Storage_Transient_Exception( 'Simulated temporary upload failure.' );
        }
        return $this->storage->put_stream( $key, $handle );
    }
    public function open_read( $key, $max_bytes = null ) {
        if ( $this->fail_read ) {
            $this->fail_read = false;
            throw new Trasloco_Storage_Transient_Exception( 'Simulated temporary download failure.' );
        }
        return $this->storage->open_read( $key, $max_bytes );
    }
    public function open_read_range( $key, $offset, $length, $total_bytes ) {
        if ( $this->fail_read ) {
            $this->fail_read = false;
            throw new Trasloco_Storage_Transient_Exception( 'Simulated temporary range failure.' );
        }
        return $this->storage->open_read_range( $key, $offset, $length, $total_bytes );
    }
    public function exists( $key ) { return $this->storage->exists( $key ); }
    public function delete( $key ) { return $this->storage->delete( $key ); }
    public function enumerate() { return $this->storage->enumerate(); }
}

class Trasloco_Tracking_Range_Storage implements Trasloco_Storage_Adapter {
    private $storage;
    public $offsets = array();

    public function __construct( $storage ) { $this->storage = $storage; }
    public function put_stream( $key, $handle ) { return $this->storage->put_stream( $key, $handle ); }
    public function open_read( $key, $max_bytes = null ) { return $this->storage->open_read( $key, $max_bytes ); }
    public function open_read_range( $key, $offset, $length, $total_bytes ) {
        $this->offsets[] = $offset;
        return $this->storage->open_read_range( $key, $offset, $length, $total_bytes );
    }
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
    $transient_archive = $local_source . '/transient-retry.wpress';
    file_put_contents( $transient_archive, "transient retry fixture\n" );
    Trasloco_Integrity::create( $transient_archive );
    $transient_store = new Trasloco_Transient_Once_Storage( $remote_store );
    $transient_source = new Trasloco_Offsite_Transfer( $transient_store, $local_source );
    $transient_target = new Trasloco_Offsite_Transfer( $transient_store, $local_target );
    offsite_assert( $transient_source->upload( 'transient-retry.wpress', true )['status'] === 'uploaded', 'Transient upload failure was not retried.' );
    $transient_store->fail_read = true;
    offsite_assert( $transient_target->download( 'transient-retry.wpress' )['status'] === 'downloaded', 'Transient download failure was not retried.' );
    offsite_assert( hash_file( 'sha256', $transient_archive ) === hash_file( 'sha256', $local_target . '/transient-retry.wpress' ), 'Retried offsite transfer changed archive bytes.' );

    $resumable_archive = $local_source . '/resume-after-interruption.wpress';
    file_put_contents( $resumable_archive, str_repeat( 'durable offsite range fixture ', 90000 ) );
    Trasloco_Integrity::create( $resumable_archive );
    $source_transfer->upload( 'resume-after-interruption.wpress', true );
    $worker = dirname( __FILE__ ) . '/offsite-resume-crash-worker.php';
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $worker ) . ' ' .
        escapeshellarg( $remote_path ) . ' ' . escapeshellarg( $local_target ) . ' ' .
        escapeshellarg( 'resume-after-interruption.wpress' );
    exec( $command, $worker_output, $worker_status );
    offsite_assert( $worker_status !== 0, 'Offsite crash fixture subprocess was not interrupted.' );
    offsite_assert( ! file_exists( $local_target . '/resume-after-interruption.wpress' ),
        'Interrupted offsite archive became visible before verification.' );
    $resume_manifest_sha = hash_file( 'sha256', $remote_path . '/resume-after-interruption.wpress.manifest.json' );
    $resume_folder = $local_target . '/.trasloco-offsite-' . hash( 'sha256', realpath( $local_target ) .
        '|resume-after-interruption.wpress|' . $resume_manifest_sha );
    offsite_assert( ( fileperms( $resume_folder ) & 0777 ) === 0700 &&
        ( fileperms( $resume_folder . '/download.part' ) & 0777 ) === 0600 &&
        ( fileperms( $resume_folder . '/manifest.json' ) & 0777 ) === 0600 &&
        ( fileperms( $resume_folder . '/transfer.json' ) & 0777 ) === 0600 &&
        ( fileperms( $resume_folder . '/transfer.lock' ) & 0777 ) === 0600,
        'Offsite resume state permissions are not private.' );
    $tracking_store = new Trasloco_Tracking_Range_Storage( $remote_store );
    $resumable_target = new Trasloco_Offsite_Transfer( $tracking_store, $local_target );
    $held_lock = fopen( $resume_folder . '/transfer.lock', 'c+b' );
    flock( $held_lock, LOCK_EX | LOCK_NB );
    offsite_throws(
        function () use ( $resumable_target ) { $resumable_target->download( 'resume-after-interruption.wpress' ); },
        'already running',
        'Concurrent offsite resume was not rejected.'
    );
    flock( $held_lock, LOCK_UN );
    fclose( $held_lock );
    file_put_contents( $resume_folder . '/download.part', 'partial trailing block', FILE_APPEND );
    $resume_metadata = json_decode( file_get_contents( $resume_folder . '/transfer.json' ), true );
    file_put_contents( $resume_folder . '/transfer.json', json_encode( array_reverse( $resume_metadata, true ) ) );
    $progress = array();
    $resume_result = $resumable_target->download( 'resume-after-interruption.wpress', function ( $bytes, $total ) use ( &$progress ) {
        $progress[] = array( $bytes, $total );
        return true;
    } );
    offsite_assert( $resume_result['status'] === 'downloaded', 'Interrupted offsite download did not resume.' );
    $last_progress = end( $progress );
    offsite_assert( count( $progress ) === 2 && $progress[0][0] === 2 * Trasloco_Offsite_Transfer::DOWNLOAD_CHUNK_BYTES &&
        $last_progress[0] === filesize( $resumable_archive ) && $last_progress[1] === filesize( $resumable_archive ),
        'Offsite progress callback did not report durable verified byte ranges.' );
    offsite_assert( $tracking_store->offsets[0] === Trasloco_Offsite_Transfer::DOWNLOAD_CHUNK_BYTES &&
        $tracking_store->offsets[1] === Trasloco_Offsite_Transfer::DOWNLOAD_CHUNK_BYTES + Trasloco_Offsite_Transfer::DOWNLOAD_CHUNK_BYTES,
        'Offsite retry did not resume at the last durable chunk boundary.' );
    offsite_assert( hash_file( 'sha256', $resumable_archive ) === hash_file( 'sha256', $local_target . '/resume-after-interruption.wpress' ),
        'Resumed offsite archive bytes differ from the source.' );
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
    $corrupt_bytes = file_get_contents( $source_archive );
    file_put_contents( $damaged, 'X' . substr( $corrupt_bytes, 1 ) );
    copy( $source_archive . '.manifest.json', $damaged . '.manifest.json' );
    offsite_throws(
        function () use ( $target_transfer ) { $target_transfer->download( 'damaged.wpress' ); },
        'integrity verification',
        'A damaged remote archive was published locally.'
    );
    offsite_assert( ! file_exists( $local_target . '/damaged.wpress' ), 'Failed transfer left an archive visible in the backup directory.' );

    file_put_contents( $remote_path . '/orphan.wpress.manifest.json', '{"incomplete":true}' );
    file_put_contents( $remote_path . '/lonely.wpress.enc', 'partial upload' );
    $inventory = $source_transfer->inventory_status();
    offsite_assert( $inventory['status'] === 'incomplete', 'Remote inventory did not report incomplete archive pairs.' );
    offsite_assert( $inventory['incomplete'] === 2, 'Remote inventory counted orphan archives or manifests incorrectly.' );
    offsite_assert( count( $inventory['archives'] ) === $inventory['complete'] + 2, 'Remote inventory summary counts are inconsistent.' );

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
