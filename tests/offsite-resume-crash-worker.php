<?php
/** Kill this subprocess after its first durable offsite download block. */
require dirname( __DIR__ ) . '/exceptions.php';
require dirname( __DIR__ ) . '/lib/model/storage/interface-trasloco-storage-adapter.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-local-storage.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-offsite-transfer.php';
require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-integrity.php';

if ( $argc !== 4 || ! function_exists( 'posix_kill' ) ) {
	fwrite( STDERR, "Usage: php offsite-resume-crash-worker.php <remote> <local> <archive> (POSIX signals required)\n" );
	exit( 2 );
}

class Trasloco_Offsite_Crash_Storage implements Trasloco_Storage_Adapter {
	private $storage;

	public function __construct( $directory ) { $this->storage = new Trasloco_Local_Storage( $directory ); }
	public function put_stream( $key, $handle ) { return $this->storage->put_stream( $key, $handle ); }
	public function open_read( $key, $max_bytes = null ) { return $this->storage->open_read( $key, $max_bytes ); }
	public function open_read_range( $key, $offset, $length, $total_bytes ) {
		if ( $offset > 0 ) {
			posix_kill( getmypid(), 9 );
			usleep( 100000 );
			exit( 91 );
		}
		return $this->storage->open_read_range( $key, $offset, $length, $total_bytes );
	}
	public function exists( $key ) { return $this->storage->exists( $key ); }
	public function delete( $key ) { return $this->storage->delete( $key ); }
	public function enumerate() { return $this->storage->enumerate(); }
}

$transfer = new Trasloco_Offsite_Transfer(
	new Trasloco_Offsite_Crash_Storage( $argv[1] ),
	$argv[2]
);
$transfer->download( $argv[3] );
