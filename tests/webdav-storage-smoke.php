<?php
/** Contract and input-safety checks for the streaming WebDAV adapter. */
if ( ! function_exists( 'wp_http_validate_url' ) ) {
    function wp_http_validate_url( $url ) {
        $host = parse_url( $url, PHP_URL_HOST );
        return parse_url( $url, PHP_URL_SCHEME ) === 'https' &&
            is_string( $host ) && $host === 'storage.example';
    }
}

require dirname( __DIR__ ) . '/lib/model/storage/interface-trasloco-storage-adapter.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-webdav-storage.php';

function webdav_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

class Trasloco_Test_WebDAV_Storage extends Trasloco_WebDAV_Storage {
    public $requests = array();
    public $responses = array();

    public function temporary_status( $status ) {
        return $this->is_transient_status( $status );
    }

    protected function resolve_host() {
        return array( 'storage.example:443:203.0.113.12' );
    }

    protected function request( $method, $key, $input = null, $output = null, $headers = array(), $max_bytes = null, &$response_headers = null ) {
        $body = null;
        if ( is_resource( $input ) ) {
            $body = stream_get_contents( $input );
        }
        $this->requests[] = array( $method, $key, $body, $headers );
        $response = array_shift( $this->responses );
        if ( $response === null ) {
            throw new RuntimeException( 'Missing WebDAV fixture response.' );
        }
        if ( is_resource( $output ) && isset( $response['body'] ) ) {
            fwrite( $output, $response['body'] );
            rewind( $output );
        }
        $response_headers = isset( $response['headers'] ) ? $response['headers'] : array();
        return $response['status'];
    }
}

$store = new Trasloco_Test_WebDAV_Storage(
    'https://storage.example/backups/',
    'backup-user',
    'fixture-secret'
);
foreach ( array( 408, 425, 429, 500, 502, 503, 504 ) as $status ) {
    webdav_assert( $store->temporary_status( $status ), 'WebDAV temporary response was not classified for retry.' );
}
foreach ( array( 400, 401, 403, 404, 412 ) as $status ) {
    webdav_assert( ! $store->temporary_status( $status ), 'WebDAV permanent/conflict response was classified for retry.' );
}
$store->responses[] = array( 'status' => 201 );
$input = fopen( 'php://temp', 'w+b' );
fwrite( $input, 'streamed encrypted backup bytes' );
rewind( $input );
$written = $store->put_stream( 'fixture.wpress.enc', $input );
fclose( $input );
webdav_assert( $written === 31, 'WebDAV PUT must report bytes streamed.' );
webdav_assert( $store->requests[0][0] === 'PUT' && $store->requests[0][1] === 'fixture.wpress.enc', 'WebDAV PUT route mismatch.' );
webdav_assert( $store->requests[0][2] === 'streamed encrypted backup bytes', 'WebDAV PUT must preserve stream bytes.' );
webdav_assert( in_array( 'If-None-Match: *', $store->requests[0][3], true ), 'WebDAV PUT must refuse object replacement.' );
$collision = fopen( 'php://temp', 'w+b' );
fwrite( $collision, 'must not replace existing object' );
rewind( $collision );
$store->responses[] = array( 'status' => 412 );
try {
    $store->put_stream( 'fixture.wpress.enc', $collision );
    throw new RuntimeException( 'WebDAV overwrite conflict was accepted.' );
} catch ( RuntimeException $expected ) {
    webdav_assert( strpos( $expected->getMessage(), 'already exists' ) !== false, 'WebDAV collision returned an unexpected error.' );
}
fclose( $collision );
$unconditional = fopen( 'php://temp', 'w+b' );
fwrite( $unconditional, 'server did not confirm create-only semantics' );
rewind( $unconditional );
$store->responses[] = array( 'status' => 204 );
try {
    $store->put_stream( 'other.wpress', $unconditional );
    throw new RuntimeException( 'WebDAV accepted a response without create-only confirmation.' );
} catch ( RuntimeException $expected ) {
    webdav_assert( strpos( $expected->getMessage(), 'did not confirm creation' ) !== false, 'Unexpected WebDAV conditional-write error.' );
}
fclose( $unconditional );

$store->responses[] = array( 'status' => 200, 'body' => 'downloaded archive bytes' );
$download = $store->open_read( 'fixture.wpress' );
webdav_assert( stream_get_contents( $download ) === 'downloaded archive bytes', 'WebDAV GET must return a readable stream.' );
fclose( $download );

$store->responses[] = array( 'status' => 206, 'body' => 'cdef',
    'headers' => array( 'content-range' => 'bytes 2-5/10' ) );
$range = $store->open_read_range( 'fixture.wpress', 2, 4, 10 );
webdav_assert( stream_get_contents( $range ) === 'cdef', 'WebDAV range GET must return exactly the requested bytes.' );
fclose( $range );
webdav_assert( in_array( 'Range: bytes=2-5', $store->requests[4][3], true ), 'WebDAV range GET omitted its byte range.' );
$store->responses[] = array( 'status' => 200, 'body' => 'whole object',
    'headers' => array() );
try {
    $store->open_read_range( 'fixture.wpress', 2, 4, 10 );
    throw new RuntimeException( 'WebDAV accepted a server that ignored the range request.' );
} catch ( RuntimeException $expected ) {
    webdav_assert( strpos( $expected->getMessage(), 'exact requested byte range' ) !== false,
        'WebDAV range refusal returned an unexpected error.' );
}

$store->responses[] = array( 'status' => 200 );
webdav_assert( $store->exists( 'fixture.wpress' ), 'Existing WebDAV object was not detected.' );
$store->responses[] = array( 'status' => 404 );
webdav_assert( ! $store->exists( 'missing.wpress' ), 'Missing WebDAV object was reported present.' );
$store->responses[] = array( 'status' => 204 );
webdav_assert( $store->delete( 'fixture.wpress' ), 'WebDAV delete failed.' );

$xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">' .
    '<d:response><d:href>/backups/</d:href></d:response>' .
    '<d:response><d:href>/backups/fixture.wpress</d:href></d:response>' .
    '<d:response><d:href>/backups/fixture.wpress.manifest.json</d:href></d:response>' .
    '<d:response><d:href>/other/secret.wpress</d:href></d:response>' .
    '<d:response><d:href>/backups/%2e%2e/secret.wpress</d:href></d:response>' .
    '</d:multistatus>';
$store->responses[] = array( 'status' => 207, 'body' => $xml );
webdav_assert(
    $store->enumerate() === array( 'fixture.wpress', 'fixture.wpress.manifest.json' ),
    'WebDAV inventory included unsafe or out-of-scope resources.'
);

foreach ( array( '../secret.wpress', 'folder/file.wpress', 'backup.txt', 'x.wpress.enc.more' ) as $unsafe_key ) {
    try {
        $store->exists( $unsafe_key );
        throw new RuntimeException( 'Unsafe WebDAV key was accepted: ' . $unsafe_key );
    } catch ( InvalidArgumentException $expected ) {
        // Expected.
    }
}

foreach ( array(
    'http://storage.example/backups/',
    'https://user:pass@storage.example/backups/',
    'https://storage.example/backups/?redirect=https://attacker.example/',
    'https://127.0.0.1/backups/',
) as $unsafe_url ) {
    try {
        new Trasloco_Test_WebDAV_Storage( $unsafe_url, 'user', 'pass' );
        throw new RuntimeException( 'Unsafe WebDAV URL was accepted.' );
    } catch ( InvalidArgumentException $expected ) {
        // Expected.
    }
}
foreach ( array( "user\r\nX-Test: injected", "user:name" ) as $unsafe_user ) {
    try {
        new Trasloco_Test_WebDAV_Storage( 'https://storage.example/backups/', $unsafe_user, 'pass' );
        throw new RuntimeException( 'Unsafe WebDAV credentials were accepted.' );
    } catch ( InvalidArgumentException $expected ) {
        // Expected.
    }
}

echo "WebDAV stream adapter contract and input-safety tests passed.\n";
