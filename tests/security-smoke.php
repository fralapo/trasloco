<?php
/**
 * Minimal CLI regression tests independent of a WordPress installation.
 * They are not a substitute for WordPress integration tests.
 */
error_reporting( E_ALL );
define( 'TRASLOCO_PLUGIN_NAME', 'trasloco' );
define( 'TRASLOCO_SECRET_KEY', 'trasloco_secret_key' );
define( 'TRASLOCO_AUTH_PASSWORD', 'trasloco_auth_password' );
define( 'TRASLOCO_BACKUPS_PATH', sys_get_temp_dir() );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['allow_admin'] = true;
function current_user_can( $capability ) { return $GLOBALS['allow_admin'] && $capability === 'manage_options'; }
function get_option( $name ) { return $name === TRASLOCO_SECRET_KEY ? 'a-mock-secret-123' : ( $name === TRASLOCO_AUTH_PASSWORD ? ( $GLOBALS['encrypted_password'] ?? false ) : false ); }
function wp_salt( $scheme = 'auth' ) { return 'fixed-test-salt'; }
function __( $message, $domain = '' ) { return $message; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=mock'; }
function validate_file( $name ) {
    return is_string( $name ) && strpos( $name, '..' ) === false && strpos( $name, '/' ) === false && strpos( $name, chr(92) ) === false ? 0 : 1;
}
require dirname( __DIR__ ) . '/exceptions.php';
require dirname( __DIR__ ) . '/functions.php';
require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-archiver.php';

function ensure( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
function denied( $token, $scope ) {
    try {
        trasloco_verify_operation_token( $token, $scope );
        return false;
    } catch ( Trasloco_Not_Valid_Secret_Key_Exception $e ) {
        return true;
    }
}
if ( function_exists( 'openssl_encrypt' ) ) {
    $GLOBALS['encrypted_password'] = trasloco_encrypt_http_password( 'private-secret' );
    ensure( strpos( $GLOBALS['encrypted_password'], 'v1:' ) === 0, 'encrypt HTTP Basic password' );
    ensure( trasloco_get_http_password() === 'private-secret', 'decrypt HTTP Basic password' );
    $GLOBALS['encrypted_password'] .= 'tampered';
    ensure( trasloco_get_http_password() === '', 'reject modified encrypted credentials' );
}
$import = trasloco_issue_operation_token( 'import' );
ensure( is_string( $import ) && strlen( $import ) > 50, 'issue token' );
ensure( trasloco_verify_operation_token( $import, 'import' ), 'verify valid token' );
ensure( denied( $import, 'export' ), 'isolate operation scopes' );
ensure( denied( $import . 'wrong', 'import' ), 'reject tampered token' );
ensure( denied( 'a-mock-secret-123', 'import' ), 'reject persistent secret as bearer token' );
$expired = time() - 60;
$signature = hash_hmac( 'sha256', 'import|' . $expired, wp_salt( 'auth' ) . '|' . get_option( TRASLOCO_SECRET_KEY ) );
ensure( denied( 'v1.' . $expired . '.' . $signature, 'import' ), 'reject expired token' );
$GLOBALS['allow_admin'] = false;
ensure( trasloco_issue_operation_token( 'import' ) === '', 'restrict token issuance' );
$GLOBALS['allow_admin'] = true;

$admin_link = trasloco_backup_url( array( 'archive' => 'valid.wpress' ) );
ensure( strpos( $admin_link, '_wpnonce=mock' ) !== false, 'admin links include session nonce' );
$GLOBALS['allow_admin'] = false;
$background_link = trasloco_backup_url( array( 'archive' => 'valid.wpress' ) );
ensure( strpos( $background_link, '_wpnonce=' ) === false, 'background links omit invalid anonymous nonce' );
$GLOBALS['allow_admin'] = true;

ensure( substr( trasloco_backup_path( array( 'archive' => 'valid.wpress' ) ), -12 ) === 'valid.wpress', 'allow archive basename' );
try {
    trasloco_backup_path( array( 'archive' => '../bad.wpress' ) );
    ensure( false, 'reject traversal' );
} catch ( Trasloco_Archive_Exception $e ) {
    // Expected.
}

require dirname( __DIR__ ) . '/lib/model/class-trasloco-manifest.php';
class Test_Trasloco_Archiver extends Trasloco_Archiver {}
$path = tempnam( sys_get_temp_dir(), 'trasloco-test-' );
$header = pack( 'a255a14a12a4096', 'hello.txt', '5', '0', '.' );
$end = str_repeat( chr(0), 4377 );
file_put_contents( $path, $header . 'hello' . $end );
$archiver = new Test_Trasloco_Archiver( $path );
ensure( $archiver->is_valid(), 'valid archive with payload and EOF' );
$archiver->close();
file_put_contents( $path, $header . 'hell' . $end );
$archiver = new Test_Trasloco_Archiver( $path );
ensure( ! $archiver->is_valid(), 'detect truncated payload' );
$archiver->close();
file_put_contents( $path, $header . 'hello' );
$archiver = new Test_Trasloco_Archiver( $path );
ensure( ! $archiver->is_valid(), 'detect missing EOF marker' );
$archiver->close();
unlink( $path );
echo "Security and archive smoke tests passed.\n";
