<?php
/** Local SDK protocol fixtures for the optional S3-compatible storage adapter. */
require dirname( __DIR__ ) . '/vendor-s3/autoload.php';
require dirname( __DIR__ ) . '/lib/model/storage/interface-trasloco-storage-adapter.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-s3-storage.php';

function s3_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function s3_stream( $value ) {
    return \GuzzleHttp\Psr7\Utils::streamFor( $value );
}

function s3_client( $responses, &$commands ) {
    $queue = new \Aws\MockHandler( $responses );
    $handler = function ( $command, $request = null ) use ( $queue, &$commands ) {
        $commands[] = $command;
        return $queue( $command, $request );
    };
    return new \Aws\S3\S3Client( array(
        'version' => '2006-03-01',
        'region' => 'us-east-1',
        'credentials' => array( 'key' => 'fixture-key', 'secret' => 'fixture-secret' ),
        'handler' => $handler,
        'retries' => 0,
    ) );
}

function s3_error( $code, $status ) {
    return new \Aws\Exception\AwsException( 'fixture error', new \Aws\Command( 'Fixture' ), array(
        'code' => $code,
        'statusCode' => $status,
    ) );
}

$commands = array();
$responses = array(
    new \Aws\Result( array( 'ETag' => '"fixture"' ) ),
    new \Aws\Result( array( 'ContentLength' => 11, 'ETag' => '"fixture-tag"' ) ),
    new \Aws\Result( array( 'ContentLength' => 11, 'Body' => s3_stream( 'hello world' ) ) ),
    new \Aws\Result( array( 'ContentLength' => 4, 'ContentRange' => 'bytes 2-5/11', 'Body' => s3_stream( 'llo ' ) ) ),
    s3_error( 'NotFound', 404 ),
    new \Aws\Result( array() ),
    new \Aws\Result( array( 'Contents' => array(
        array( 'Key' => 'trasloco/site-one.wpress.enc', 'Size' => 5 ),
        array( 'Key' => 'trasloco/site-one.wpress.enc.manifest.json', 'Size' => 3 ),
        array( 'Key' => 'trasloco/ignored.txt', 'Size' => 1 ),
    ), 'IsTruncated' => true, 'NextContinuationToken' => 'page-2' ) ),
    new \Aws\Result( array( 'Contents' => array(
        array( 'Key' => 'trasloco/site-two.wpress', 'Size' => 8 ),
    ), 'IsTruncated' => false ) ),
    s3_error( 'PreconditionFailed', 412 ),
    s3_error( 'SlowDown', 503 ),
);
$client = s3_client( $responses, $commands );
$store = new Trasloco_S3_Storage( array(
    'bucket' => 'trasloco-backups',
    'region' => 'us-east-1',
    'prefix' => 'trasloco',
), $client );

$upload = fopen( 'php://temp', 'w+b' );
fwrite( $upload, 'fixture bytes' );
rewind( $upload );
s3_assert( $store->put_stream( 'site-one.wpress.enc', $upload ) === 13, 'S3 upload byte count mismatch.' );
fclose( $upload );
s3_assert( $commands[0]->getName() === 'PutObject', 'Small S3 upload did not use PutObject.' );
s3_assert( $commands[0]['IfNoneMatch'] === '*', 'S3 upload must use create-only conditional writes.' );
s3_assert( $commands[0]['Key'] === 'trasloco/site-one.wpress.enc', 'S3 prefix was not applied.' );

$download = $store->open_read( 'site-one.wpress.enc', 11 );
s3_assert( stream_get_contents( $download ) === 'hello world', 'S3 download bytes mismatch.' );
fclose( $download );
$range = $store->open_read_range( 'site-one.wpress.enc', 2, 4, 11 );
s3_assert( stream_get_contents( $range ) === 'llo ', 'S3 range bytes mismatch.' );
fclose( $range );
s3_assert( $commands[3]['Range'] === 'bytes=2-5', 'S3 range header mismatch.' );
s3_assert( $commands[2]['IfMatch'] === '"fixture-tag"', 'Bounded S3 reads must use the checked object version.' );
s3_assert( ! $store->exists( 'missing.wpress' ), 'Missing S3 object was reported present.' );
s3_assert( $store->delete( 'site-one.wpress.enc' ), 'S3 delete failed.' );
s3_assert( $store->enumerate() === array(
    'site-one.wpress.enc', 'site-one.wpress.enc.manifest.json', 'site-two.wpress',
), 'S3 inventory included unsafe, irrelevant or out-of-prefix objects.' );
s3_assert( $commands[7]['ContinuationToken'] === 'page-2', 'S3 inventory did not follow its continuation token.' );

$collision = fopen( 'php://temp', 'w+b' );
fwrite( $collision, 'collision' );
rewind( $collision );
try {
    $store->put_stream( 'site-one.wpress.enc', $collision );
    throw new RuntimeException( 'S3 accepted an overwrite conflict.' );
} catch ( RuntimeException $error ) {
    s3_assert( strpos( $error->getMessage(), 'already exists' ) !== false, 'S3 conflict was not identified safely.' );
}
fclose( $collision );
$temporary = fopen( 'php://temp', 'w+b' );
fwrite( $temporary, 'retry later' );
rewind( $temporary );
try {
    $store->put_stream( 'site-one.wpress.enc', $temporary );
    throw new RuntimeException( 'S3 temporary error was ignored.' );
} catch ( Trasloco_Storage_Transient_Exception $error ) {
    s3_assert( strpos( $error->getMessage(), 'temporary error' ) !== false, 'S3 transient error classification mismatch.' );
}
fclose( $temporary );

$multipart_commands = array();
$multipart_responses = array(
    new \Aws\Result( array( 'UploadId' => 'fixture-upload' ) ),
    new \Aws\Result( array( 'ETag' => '"part-1"' ) ),
    new \Aws\Result( array( 'ETag' => '"part-2"' ) ),
    new \Aws\Result( array( 'ETag' => '"part-3"' ) ),
    new \Aws\Result( array( 'ETag' => '"complete"' ) ),
);
$multipart_client = s3_client( $multipart_responses, $multipart_commands );
$multipart_store = new Trasloco_S3_Storage( array( 'bucket' => 'trasloco-backups', 'region' => 'us-east-1' ), $multipart_client );
$large_upload = fopen( 'php://temp/maxmemory:2097152', 'w+b' );
$remaining = 17 * 1024 * 1024;
$chunk = str_repeat( 'x', 1024 * 1024 );
while ( $remaining > 0 ) {
    $piece = min( $remaining, strlen( $chunk ) );
    fwrite( $large_upload, substr( $chunk, 0, $piece ) );
    $remaining -= $piece;
}
rewind( $large_upload );
s3_assert( $multipart_store->put_stream( 'large.wpress', $large_upload ) === 17 * 1024 * 1024, 'S3 multipart byte count mismatch.' );
fclose( $large_upload );
s3_assert( $multipart_commands[0]->getName() === 'CreateMultipartUpload', 'Large S3 upload did not initiate multipart upload.' );
s3_assert( $multipart_commands[4]->getName() === 'CompleteMultipartUpload', 'Large S3 upload did not complete multipart upload.' );
s3_assert( $multipart_commands[4]['IfNoneMatch'] === '*', 'S3 multipart completion must be create-only.' );
s3_assert( $multipart_commands[0]['Key'] === 'large.wpress', 'S3 multipart key mismatch.' );

$bad_prefixes = array( '../escape', 'safe//path', 'safe/../escape', 'safe/./path' );
foreach ( $bad_prefixes as $prefix ) {
    try {
        new Trasloco_S3_Storage( array( 'bucket' => 'trasloco-backups', 'region' => 'us-east-1', 'prefix' => $prefix ), $client );
        throw new RuntimeException( 'Unsafe S3 prefix was accepted.' );
    } catch ( InvalidArgumentException $error ) {
        s3_assert( strpos( $error->getMessage(), 'prefix' ) !== false, 'Unsafe S3 prefix returned an unexpected error.' );
    }
}
try {
    new Trasloco_S3_Storage( array(
        'bucket' => 'trasloco-backups', 'region' => 'us-east-1', 'endpoint' => 'http://storage.example',
    ), $client );
    throw new RuntimeException( 'Insecure S3 endpoint was accepted.' );
} catch ( InvalidArgumentException $error ) {
    s3_assert( strpos( $error->getMessage(), 'HTTPS' ) !== false, 'Insecure endpoint returned an unexpected error.' );
}

$bounded_commands = array();
$bounded_client = s3_client( array(
    new \Aws\Result( array( 'ContentLength' => 12, 'ETag' => '"too-large"' ) ),
), $bounded_commands );
$bounded_store = new Trasloco_S3_Storage( array( 'bucket' => 'trasloco-backups', 'region' => 'us-east-1' ), $bounded_client );
try {
    $bounded_store->open_read( 'oversized.wpress.manifest.json', 11 );
    throw new RuntimeException( 'Oversized S3 object passed its pre-download bound.' );
} catch ( RuntimeException $error ) {
    s3_assert( strpos( $error->getMessage(), 'exceeds the allowed limit' ) !== false, 'S3 size limit returned an unexpected error.' );
}
s3_assert( count( $bounded_commands ) === 1 && $bounded_commands[0]->getName() === 'HeadObject',
    'S3 fetched an oversized object body before enforcing its size limit.' );

echo "S3 adapter SDK fixtures passed.\n";
