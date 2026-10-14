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
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-local-storage.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-webdav-storage.php';
require dirname( __DIR__ ) . '/exceptions.php';
require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-integrity.php';
require dirname( __DIR__ ) . '/lib/model/storage/class-trasloco-offsite-transfer.php';

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

/** In-memory WebDAV protocol fixture for multipart commit/resume behavior. */
class Trasloco_Test_Resumable_WebDAV_Storage extends Trasloco_WebDAV_Storage {
    public $objects = array();

    protected function resolve_host() {
        return array( 'storage.example:443:203.0.113.12' );
    }

    protected function request( $method, $key, $input = null, $output = null, $headers = array(), $max_bytes = null, &$response_headers = null ) {
        $response_headers = array();
        if ( $method === 'PUT' ) {
            if ( isset( $this->objects[ $key ] ) ) { return 412; }
            $body = stream_get_contents( $input );
            if ( ! is_string( $body ) ) { throw new RuntimeException( 'Invalid fixture upload stream.' ); }
            $this->objects[ $key ] = $body;
            return 201;
        }
        if ( $method === 'HEAD' ) {
            return isset( $this->objects[ $key ] ) ? 200 : 404;
        }
        if ( $method === 'GET' ) {
            if ( ! isset( $this->objects[ $key ] ) ) { return 404; }
            $body = $this->objects[ $key ];
            foreach ( $headers as $header ) {
                if ( preg_match( '/^Range: bytes=([0-9]+)-([0-9]+)$/', $header, $match ) ) {
                    $start = (int) $match[1];
                    $end = (int) $match[2];
                    if ( $end >= strlen( $body ) ) { return 416; }
                    $body = substr( $body, $start, $end - $start + 1 );
                    $response_headers['content-range'] = 'bytes ' . $start . '-' . $end . '/' . strlen( $this->objects[ $key ] );
                    if ( is_resource( $output ) ) { fwrite( $output, $body ); }
                    return 206;
                }
            }
            if ( $max_bytes !== null && strlen( $body ) > $max_bytes ) { return 413; }
            if ( is_resource( $output ) ) { fwrite( $output, $body ); }
            return 200;
        }
        if ( $method === 'MOVE' ) {
            $destination = '';
            foreach ( $headers as $header ) {
                if ( strpos( $header, 'Destination: ' ) === 0 ) {
                    $destination = basename( rawurldecode( parse_url( substr( $header, 13 ), PHP_URL_PATH ) ) );
                }
            }
            if ( ! isset( $this->objects[ $key ] ) ) { return 404; }
            if ( isset( $this->objects[ $destination ] ) ) { return 412; }
            $this->objects[ $destination ] = $this->objects[ $key ];
            unset( $this->objects[ $key ] );
            return 201;
        }
        if ( $method === 'DELETE' ) {
            unset( $this->objects[ $key ] );
            return 204;
        }
        if ( $method === 'PROPFIND' ) {
            $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">';
            foreach ( array_keys( $this->objects ) as $object_key ) {
                $xml .= '<d:response><d:href>/backups/' . htmlspecialchars( $object_key, ENT_XML1 ) . '</d:href></d:response>';
            }
            $xml .= '</d:multistatus>';
            if ( is_resource( $output ) ) { fwrite( $output, $xml ); }
            return 207;
        }
        return 405;
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

$store->responses[] = array( 'status' => 404 );
$store->responses[] = array( 'status' => 200, 'body' => 'downloaded archive bytes' );
$download = $store->open_read( 'fixture.wpress' );
webdav_assert( stream_get_contents( $download ) === 'downloaded archive bytes', 'WebDAV GET must return a readable stream.' );
fclose( $download );

$store->responses[] = array( 'status' => 404 );
$store->responses[] = array( 'status' => 206, 'body' => 'cdef',
    'headers' => array( 'content-range' => 'bytes 2-5/10' ) );
$range = $store->open_read_range( 'fixture.wpress', 2, 4, 10 );
webdav_assert( stream_get_contents( $range ) === 'cdef', 'WebDAV range GET must return exactly the requested bytes.' );
fclose( $range );
$range_request = end( $store->requests );
webdav_assert( in_array( 'Range: bytes=2-5', $range_request[3], true ), 'WebDAV range GET omitted its byte range.' );
$store->responses[] = array( 'status' => 404 );
$store->responses[] = array( 'status' => 200, 'body' => 'whole object',
    'headers' => array() );
try {
    $store->open_read_range( 'fixture.wpress', 2, 4, 10 );
    throw new RuntimeException( 'WebDAV accepted a server that ignored the range request.' );
} catch ( RuntimeException $expected ) {
    webdav_assert( strpos( $expected->getMessage(), 'exact requested byte range' ) !== false,
        'WebDAV range refusal returned an unexpected error.' );
}

$store->responses[] = array( 'status' => 404 );
$store->responses[] = array( 'status' => 200 );
webdav_assert( $store->exists( 'fixture.wpress' ), 'Existing WebDAV object was not detected.' );
$store->responses[] = array( 'status' => 404 );
$store->responses[] = array( 'status' => 404 );
webdav_assert( ! $store->exists( 'missing.wpress' ), 'Missing WebDAV object was reported present.' );
$store->responses[] = array( 'status' => 404 );
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

$multipart = new Trasloco_Test_Resumable_WebDAV_Storage(
    'https://storage.example/backups/', 'backup-user', 'fixture-secret'
);
$multipart_path = tempnam( sys_get_temp_dir(), 'trasloco-webdav-upload-' );
$multipart_bytes = str_repeat( 'durable resumable WebDAV part ', 300000 );
file_put_contents( $multipart_path, $multipart_bytes );
$multipart_digest = hash( 'sha256', $multipart_bytes );
$stopped_at_first_part = false;
try {
    $multipart->put_resumable( 'resumable.wpress', $multipart_path, strlen( $multipart_bytes ), $multipart_digest,
        function ( $bytes, $total ) use ( &$stopped_at_first_part ) {
            $stopped_at_first_part = $bytes === Trasloco_WebDAV_Storage::RESUMABLE_CHUNK_BYTES;
            return ! $stopped_at_first_part;
        }
    );
    throw new RuntimeException( 'Interrupted multipart upload did not stop at its persisted checkpoint.' );
} catch ( RuntimeException $expected ) {
    webdav_assert( strpos( $expected->getMessage(), 'cancelled' ) !== false,
        'Interrupted multipart upload returned an unexpected error.' );
}
webdav_assert( $stopped_at_first_part && ! $multipart->exists( 'resumable.wpress' ),
    'Incomplete WebDAV multipart upload became visible as an archive.' );
$progress = array();
webdav_assert( $multipart->put_resumable( 'resumable.wpress', $multipart_path,
    strlen( $multipart_bytes ), $multipart_digest, function ( $bytes, $total ) use ( &$progress ) {
        $progress[] = array( $bytes, $total );
        return true;
    } ), 'Resumed WebDAV multipart upload was not published.' );
webdav_assert( count( $progress ) === 2 &&
    $progress[0][0] === Trasloco_WebDAV_Storage::RESUMABLE_CHUNK_BYTES &&
    $progress[1][0] === strlen( $multipart_bytes ) && $progress[1][1] === strlen( $multipart_bytes ),
    'Resumed WebDAV upload did not report durable part progress.' );
webdav_assert( ! $multipart->put_resumable( 'resumable.wpress', $multipart_path,
    strlen( $multipart_bytes ), $multipart_digest ), 'Completed WebDAV multipart retry was not idempotent.' );
$assembled = $multipart->open_read( 'resumable.wpress', strlen( $multipart_bytes ) );
webdav_assert( stream_get_contents( $assembled ) === $multipart_bytes,
    'WebDAV multipart archive could not be assembled and verified.' );
fclose( $assembled );
$cross_boundary = $multipart->open_read_range( 'resumable.wpress',
    Trasloco_WebDAV_Storage::RESUMABLE_CHUNK_BYTES - 3, 10, strlen( $multipart_bytes ) );
webdav_assert( stream_get_contents( $cross_boundary ) === substr( $multipart_bytes,
    Trasloco_WebDAV_Storage::RESUMABLE_CHUNK_BYTES - 3, 10 ),
    'WebDAV multipart ranged read returned incorrect bytes across a part boundary.' );
fclose( $cross_boundary );
webdav_assert( in_array( 'resumable.wpress', $multipart->enumerate(), true ),
    'WebDAV multipart inventory did not expose the logical archive.' );
webdav_assert( $multipart->delete( 'resumable.wpress' ) &&
    ! $multipart->exists( 'resumable.wpress' ), 'WebDAV multipart archive was not removed safely.' );
unlink( $multipart_path );

$integration_root = sys_get_temp_dir() . '/trasloco-webdav-logical-' . bin2hex( random_bytes( 6 ) );
mkdir( $integration_root, 0700 );
$integration_archive = $integration_root . '/upload-resumable.wpress';
$integration_bytes = str_repeat( 'verified WebDAV transfer object ', 270000 );
file_put_contents( $integration_archive, $integration_bytes );
Trasloco_Integrity::create( $integration_archive );
$integration_transfer = new Trasloco_Offsite_Transfer( $multipart, $integration_root );
$integration_progress = array();
$upload_result = $integration_transfer->upload( 'upload-resumable.wpress', true,
    function ( $bytes, $total ) use ( &$integration_progress ) {
        $integration_progress[] = array( $bytes, $total );
        return true;
    }
);
webdav_assert( $upload_result['status'] === 'uploaded' && count( $integration_progress ) === 2,
    'Verified offsite transfer did not use resumable WebDAV parts.' );
$download_root = $integration_root . '/download';
mkdir( $download_root, 0700 );
$download_transfer = new Trasloco_Offsite_Transfer( $multipart, $download_root );
$download_result = $download_transfer->download( 'upload-resumable.wpress' );
webdav_assert( $download_result['status'] === 'downloaded' &&
    hash_file( 'sha256', $integration_archive ) === hash_file( 'sha256', $download_root . '/upload-resumable.wpress' ),
    'Verified offsite download could not read the logical WebDAV multipart archive.' );
$remote_report = $integration_transfer->inventory_status();
webdav_assert( $remote_report['status'] === 'healthy' && $remote_report['complete'] === 1,
    'Logical WebDAV multipart upload was not visible as a complete archive/manifest pair.' );
unlink( $integration_archive . '.manifest.json' );
unlink( $integration_archive );
unlink( $download_root . '/upload-resumable.wpress.manifest.json' );
unlink( $download_root . '/upload-resumable.wpress' );
foreach ( glob( $download_root . '/.trasloco-offsite-*' ) as $resume_directory ) {
    if ( is_dir( $resume_directory ) && ! is_link( $resume_directory ) ) {
        foreach ( glob( $resume_directory . '/*' ) as $resume_file ) {
            if ( is_file( $resume_file ) && ! is_link( $resume_file ) ) { unlink( $resume_file ); }
        }
        rmdir( $resume_directory );
    }
}
rmdir( $download_root );
rmdir( $integration_root );

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
