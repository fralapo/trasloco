<?php
/**
 * Emulates a remote peer over WordPress's HTTP interception hook.
 * Exercises the actual resumable downloader without reaching the internet.
 * Executed with WP-CLI eval-file inside a throwaway WordPress test site.
 */
$files = glob( WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'trasloco-backups' . DIRECTORY_SEPARATOR . '*.wpress' );
if ( empty( $files ) ) {
    throw new RuntimeException( 'Peer test source fixture is missing.' );
}
$source = $files[0];
$name = 'peer-smoke-fixture.wpress';
$base = 'https://example.com/';
$user = 'fixture-user';
$password = 'test-application-password';
$expected = array(
    'archive' => $name,
    'bytes' => filesize( $source ),
    'sha256' => hash_file( 'sha256', $source ),
);
$identity = hash( 'sha256', rtrim( $base, '/' ) . '|' . $user . '|' . $name );
$folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-peer-' . $identity;
if ( ! is_dir( $folder ) && ! mkdir( $folder, 0700 ) ) {
    throw new RuntimeException( 'Could not create resumable fixture directory.' );
}
file_put_contents( $folder . '/transfer.json', json_encode( $expected ) );
$half = min( 524288, max( 1, (int) floor( $expected['bytes'] / 2 ) ) );
$handle = fopen( $source, 'rb' );
file_put_contents( $folder . '/download.part', fread( $handle, $half ) );
fclose( $handle );
$firstOffset = null;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $name, $source, $expected, &$firstOffset ) {
    $prefix = 'https://example.com/wp-json/trasloco/v1/backups/' . $name . '/';
    if ( strpos( $url, $prefix ) !== 0 ) {
        return $pre;
    }
    if ( empty( $args['headers']['Authorization'] ) || $args['headers']['Authorization'] !== 'Basic ' . base64_encode( 'fixture-user:test-application-password' ) ) {
        throw new RuntimeException( 'Peer request omitted required credentials.' );
    }
    if ( strpos( $url, '/manifest' ) !== false ) {
        $payload = $expected;
    } else {
        // Query contains offset=N; parse it safely.
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
        $offset = isset( $query['offset'] ) ? (int) $query['offset'] : -1;
        if ( $firstOffset === null ) {
            $firstOffset = $offset;
        }
        $stream = fopen( $source, 'rb' );
        fseek( $stream, $offset );
        $part = fread( $stream, 524288 );
        fclose( $stream );
        $payload = array(
            'offset' => $offset,
            'length' => strlen( $part ),
            'data' => base64_encode( $part ),
            'sha256' => hash( 'sha256', $part ),
        );
    }
    return array( 'headers' => array(), 'body' => wp_json_encode( $payload ),
        'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );

$downloaded = Trasloco_Peer::pull( $base, $name, $user, $password );
if ( $firstOffset !== $half ) {
    throw new RuntimeException( 'Peer transfer did not resume at the saved offset.' );
}
if ( hash_file( 'sha256', $downloaded ) !== $expected['sha256'] ) {
    throw new RuntimeException( 'Peer transfer produced a different archive.' );
}
if ( Trasloco_Manifest::inspect( $name )['status'] !== 'verified' ) {
    throw new RuntimeException( 'Downloaded peer manifest is invalid.' );
}
try {
    Trasloco_Peer::pull( $base, $name, $user, $password );
    throw new RuntimeException( 'Expected refusal to overwrite existing peer archive.' );
} catch ( RuntimeException $e ) {
    if ( strpos( $e->getMessage(), 'already exists' ) === false ) {
        throw $e;
    }
}
$model = new Trasloco_Backups();
$model->delete_file( $name );
echo "Resumable WordPress-to-WordPress peer transport smoke test passed.\n";
