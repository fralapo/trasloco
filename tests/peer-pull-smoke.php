<?php
/** Local transport simulation for the resumable peer downloader. */
// The same test runs under WordPress integration and as a standalone quality
// check. Supply only the small WordPress surface used by the HTTP filter when
// Core has not already been bootstrapped.
if ( ! defined( 'TRASLOCO_LIB_PATH' ) ) {
	define( 'TRASLOCO_LIB_PATH', dirname( __DIR__ ) . '/lib' );
}

$standalone_root = null;
if ( ! defined( 'TRASLOCO_BACKUPS_PATH' ) ) {
	$standalone_root = sys_get_temp_dir() . '/trasloco-peer-smoke-' . bin2hex( random_bytes( 8 ) );
	$standalone_backups = $standalone_root . '/wp-content/trasloco-backups';
	if ( ! mkdir( $standalone_backups, 0700, true ) ) {
		throw new RuntimeException( 'Could not create standalone peer test directory.' );
	}
	define( 'WP_CONTENT_DIR', $standalone_root . '/wp-content' );
	define( 'ABSPATH', $standalone_root . '/' );
	define( 'TRASLOCO_BACKUPS_PATH', $standalone_backups );

	function trasloco_backup_path( $params ) {
		return TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $params['archive'];
	}
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
	function wp_parse_url( $url ) {
		return parse_url( $url );
	}
	function wp_http_validate_url( $url ) {
		return is_string( $url ) && (bool) filter_var( $url, FILTER_VALIDATE_URL ) &&
			strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) === 'https';
	}
	function wp_mkdir_p( $directory ) {
		return is_dir( $directory ) || mkdir( $directory, 0700, true );
	}
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['trasloco_peer_smoke_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['trasloco_peer_smoke_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['trasloco_peer_smoke_filters'][ $hook ][ $priority ] as $index => $filter ) {
			if ( $filter[0] === $callback ) {
				unset( $GLOBALS['trasloco_peer_smoke_filters'][ $hook ][ $priority ][ $index ] );
				return true;
			}
		}
		return false;
	}
	function wp_safe_remote_get( $url, $args = array() ) {
		$pre = false;
		$filters = isset( $GLOBALS['trasloco_peer_smoke_filters']['pre_http_request'] )
			? $GLOBALS['trasloco_peer_smoke_filters']['pre_http_request'] : array();
		ksort( $filters );
		foreach ( $filters as $callbacks ) {
			foreach ( $callbacks as $filter ) {
				$pre = call_user_func_array( $filter[0], array_slice( array( $pre, $args, $url ), 0, $filter[1] ) );
			}
		}
		return $pre;
	}
	function is_wp_error( $value ) {
		return false;
	}
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-peer.php';
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
    if ( $standalone_root !== null ) {
		@rmdir( TRASLOCO_BACKUPS_PATH );
		@rmdir( WP_CONTENT_DIR );
		@rmdir( $standalone_root );
	}
}
echo "Resumable WordPress peer transfer smoke test passed.\n";
