<?php
/** Local transport simulation for the resumable peer downloader. */
require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
$base = 'https://example.com';
$name = 'peer-smoke-fixture.wpress';
$source_name = 'peer-source-fixture.wpress';
$source = trasloco_backup_path( array( 'archive' => $source_name ) );
$destination = trasloco_backup_path( array( 'archive' => $name ) );
$user = 'fixture-user';
$password = 'test-application-password';
$payload = str_repeat( 'Trasloco-peer-fixture-', 50000 );
if ( file_put_contents( $source, $payload, LOCK_EX ) !== strlen( $payload ) ) {
    throw new RuntimeException( 'Could not create local peer source fixture.' );
}
Trasloco_Integrity::create_chunked( $source );
$expected = array( 'archive' => $name, 'bytes' => filesize( $source ), 'sha256' => hash_file( 'sha256', $source ) );
$identity = hash( 'sha256', $base . '|' . $user . '|' . $name );
$folder = realpath( sys_get_temp_dir() ) . DIRECTORY_SEPARATOR . 'trasloco-peer-' . $identity;
if ( is_dir( $folder ) ) {
    foreach ( glob( $folder . DIRECTORY_SEPARATOR . '*' ) as $leftover ) { @unlink( $leftover ); }
    @rmdir( $folder );
}
if ( ! mkdir( $folder, 0700 ) ) {
    throw new RuntimeException( 'Could not create resumable fixture directory.' );
}
// JSON object member order has no meaning; older resumable state used another order.
file_put_contents( $folder . '/transfer.json', wp_json_encode( array(
    'sha256' => $expected['sha256'], 'archive' => $expected['archive'], 'bytes' => $expected['bytes'],
) ) );
$partial_bytes = Trasloco_Peer::CHUNK_BYTES + 37;
$in = fopen( $source, 'rb' );
$partial = fread( $in, $partial_bytes );
fclose( $in );
file_put_contents( $folder . '/download.part', $partial );
$first_offset = null;
$peer_filter = function ( $pre, $args, $url ) use ( $name, $source, $expected, $user, $password, &$first_offset ) {
    $prefix = 'https://example.com/wp-json/trasloco/v1/backups/' . $name . '/';
    if ( strpos( $url, $prefix ) !== 0 ) { return $pre; }
    if ( empty( $args['headers']['Authorization'] ) ||
        $args['headers']['Authorization'] !== 'Basic ' . base64_encode( $user . ':' . $password ) ) {
        throw new RuntimeException( 'Peer request omitted required credentials.' );
    }
    if ( strpos( $url, '/manifest' ) !== false ) {
        $response = $expected;
    } else {
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
        $offset = isset( $query['offset'] ) ? (int) $query['offset'] : -1;
        if ( $first_offset === null ) { $first_offset = $offset; }
        $stream = fopen( $source, 'rb' );
        fseek( $stream, $offset );
        $bytes = fread( $stream, min( Trasloco_Peer::CHUNK_BYTES, filesize( $source ) - $offset ) );
        fclose( $stream );
        $response = array( 'offset' => $offset, 'length' => strlen( $bytes ),
            'data' => base64_encode( $bytes ), 'sha256' => hash( 'sha256', $bytes ) );
    }
    return array( 'headers' => array(), 'body' => wp_json_encode( $response ),
        'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $peer_filter, 10, 3 );

try {
    $downloaded = Trasloco_Peer::pull( $base, $name, $user, $password );
    if ( $first_offset !== Trasloco_Peer::CHUNK_BYTES || hash_file( 'sha256', $downloaded ) !== $expected['sha256'] ) {
        throw new RuntimeException( 'Peer transfer did not resume at a safe frame boundary.' );
    }
    if ( Trasloco_Integrity::verify( $downloaded )['status'] !== 'verified' ) {
        throw new RuntimeException( 'Downloaded peer manifest is invalid.' );
    }
    if ( Trasloco_Peer::pull( $base, $name, $user, $password ) !== $destination ) {
        throw new RuntimeException( 'Identical peer archive retry was not idempotent.' );
    }
    @unlink( $destination );
    @unlink( Trasloco_Integrity::manifest_path( $destination ) );
    file_put_contents( $destination, 'different pre-existing bytes', LOCK_EX );
    try {
        Trasloco_Peer::pull( $base, $name, $user, $password );
        throw new RuntimeException( 'Expected refusal to overwrite a different local archive.' );
    } catch ( RuntimeException $error ) {
        if ( strpos( $error->getMessage(), 'different contents' ) === false ) { throw $error; }
    }
} finally {
    remove_filter( 'pre_http_request', $peer_filter, 10 );
    @unlink( $destination );
    @unlink( Trasloco_Integrity::manifest_path( $destination ) );
    @unlink( $source );
    @unlink( Trasloco_Integrity::manifest_path( $source ) );
    @unlink( $folder . DIRECTORY_SEPARATOR . 'download.part' );
    @unlink( $folder . DIRECTORY_SEPARATOR . 'transfer.json' );
    @rmdir( $folder );
}
echo "Resumable WordPress peer transfer smoke test passed.\n";
