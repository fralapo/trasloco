<?php
/** Exercise real AWS SDK serialization and response parsing over a local S3 protocol fixture. */
require dirname( __DIR__ ) . '/vendor-s3/autoload.php';
require dirname( __DIR__ ) . '/lib/model/storage/interface-trasloco-storage-adapter.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-s3-storage.php';

function s3_wire_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

$port = isset( $argv[1] ) ? (int) $argv[1] : 0;
$state_path = getenv( 'TRASLOCO_S3_WIRE_STATE' );
if ( $port < 1 || $port > 65535 || ! is_string( $state_path ) || ! is_file( $state_path ) ) {
    throw new RuntimeException( 'S3 wire fixture server is not ready.' );
}
$client = new \Aws\S3\S3Client( array(
    'version' => '2006-03-01',
    'region' => 'us-east-1',
    'endpoint' => 'http://127.0.0.1:' . $port,
    'use_path_style_endpoint' => true,
    'credentials' => array( 'key' => 'wire-fixture-key', 'secret' => 'wire-fixture-secret' ),
    'retries' => 0,
    'http' => array( 'connect_timeout' => 2, 'timeout' => 5, 'allow_redirects' => false, 'expect' => false ),
) );
$store = new Trasloco_S3_Storage( array(
    'bucket' => 'trasloco-backups',
    'region' => 'us-east-1',
    'prefix' => 'wire/site-a',
    // The adapter validates its configured endpoint even when the test injects
    // an SDK client whose requests are routed to the local HTTP fixture.
    'endpoint' => 'https://objects.fixture.invalid',
), $client );

foreach ( array( 'wire/site-a/site-one.wpress.enc' => 'wire payload bytes',
    'wire/site-a/site-one.wpress.enc.manifest.json' => '{"sha256":"fixture"}' ) as $key => $bytes ) {
    $stream = fopen( 'php://temp', 'w+b' );
    fwrite( $stream, $bytes );
    rewind( $stream );
    $name = substr( $key, strlen( 'wire/site-a/' ) );
    s3_wire_assert( $store->put_stream( $name, $stream ) === strlen( $bytes ), 'S3 wire upload length mismatch.' );
    fclose( $stream );
}

$collision = fopen( 'php://temp', 'w+b' );
fwrite( $collision, 'replacement bytes' );
rewind( $collision );
try {
    $store->put_stream( 'site-one.wpress.enc', $collision );
    throw new RuntimeException( 'S3 wire fixture accepted an overwrite.' );
} catch ( RuntimeException $error ) {
    s3_wire_assert( strpos( $error->getMessage(), 'already exists' ) !== false, 'S3 wire collision mapping mismatch.' );
}
fclose( $collision );

$download = $store->open_read( 'site-one.wpress.enc', strlen( 'wire payload bytes' ) );
s3_wire_assert( stream_get_contents( $download ) === 'wire payload bytes', 'S3 wire GET body mismatch.' );
fclose( $download );
$range = $store->open_read_range( 'site-one.wpress.enc', 5, 7, strlen( 'wire payload bytes' ) );
s3_wire_assert( stream_get_contents( $range ) === 'payload', 'S3 wire range response mismatch.' );
fclose( $range );
s3_wire_assert( $store->exists( 'site-one.wpress.enc' ), 'S3 wire HEAD did not find the uploaded object.' );
s3_wire_assert( $store->enumerate() === array( 'site-one.wpress.enc', 'site-one.wpress.enc.manifest.json' ),
    'S3 wire ListObjectsV2 response did not produce the expected inventory.' );
s3_wire_assert( $store->delete( 'site-one.wpress.enc' ), 'S3 wire DELETE failed.' );
s3_wire_assert( ! $store->exists( 'site-one.wpress.enc' ), 'S3 wire DELETE left the object visible.' );

$fixture = json_decode( file_get_contents( $state_path ), true );
s3_wire_assert( is_array( $fixture ) && ! empty( $fixture['requests'] ), 'S3 wire fixture did not record requests.' );
foreach ( $fixture['requests'] as $request ) {
    s3_wire_assert( ! empty( $request['authenticated'] ), 'AWS SDK request did not use Signature Version 4.' );
}
$put = $fixture['requests'][0];
s3_wire_assert( $put['method'] === 'PUT' && $put['if_none_match'] === '*' && $put['body'] === 'wire payload bytes',
    'S3 wire PUT did not preserve its signed create-only payload.' );
$range_requests = array_values( array_filter( $fixture['requests'], function ( $request ) {
    return $request['range'] !== '';
} ) );
s3_wire_assert( count( $range_requests ) === 1 && $range_requests[0]['range'] === 'bytes=5-11',
    'AWS SDK did not serialize the exact S3 byte range.' );

echo "S3 AWS SDK wire protocol fixture passed.\n";
