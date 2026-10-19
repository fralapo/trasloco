<?php
/** Local mocked-HTTP security checks for the read-only agency overview. */
if ( ! defined( 'TRASLOCO_PLUGIN_NAME' ) ) {
	define( 'TRASLOCO_PLUGIN_NAME', 'trasloco' );
}
define( 'TRASLOCO_PATH', dirname( __DIR__ ) );

class Agency_Smoke_WP_Error {}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( (string) $parts['scheme'] ) !== 'https' ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( $host === 'localhost' || substr( $host, -9 ) === '.internal' ||
		filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}
	return true;
}

function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['agency_smoke_requests'][] = array( 'url' => $url, 'args' => $args );
	return isset( $GLOBALS['agency_smoke_response'] ) ? $GLOBALS['agency_smoke_response'] : new Agency_Smoke_WP_Error();
}

function is_wp_error( $value ) {
	return $value instanceof Agency_Smoke_WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function get_transient( $key ) {
	return isset( $GLOBALS['agency_smoke_transients'][ $key ] ) ? $GLOBALS['agency_smoke_transients'][ $key ] : false;
}

function set_transient( $key, $value, $expiration ) {
	$GLOBALS['agency_smoke_transients'][ $key ] = $value;
	$GLOBALS['agency_smoke_ttls'][ $key ] = $expiration;
	return true;
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $text, $domain = null ) { echo esc_html__( $text, $domain ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr__( $text, $domain ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function current_user_can( $capability ) { return ! empty( $GLOBALS['agency_smoke_admin'] ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function add_menu_page() { $GLOBALS['agency_smoke_menus'][] = func_get_args(); }
function add_submenu_page() { $GLOBALS['agency_smoke_submenus'][] = func_get_args(); }

class Trasloco_Template {
	public static $html = '';
	public static function render( $view, $args = array() ) {
		extract( $args, EXTR_SKIP );
		ob_start();
		include dirname( __DIR__ ) . '/lib/view/' . $view . '.php';
		self::$html = ob_get_clean();
	}
}

$configured_sites = array();
for ( $i = 0; $i < 16; $i++ ) {
	$configured_sites[] = array(
		'name'     => $i === 0 ? '<script>Agency</script>' : 'Client ' . $i,
		'url'      => 'https://agency.example/',
		'user'     => 'readonly-user',
		'password' => 'revocable-app-password-secret',
	);
}
define( 'TRASLOCO_AGENCY_SITES', $configured_sites );
require_once dirname( __DIR__ ) . '/lib/model/class-trasloco-agency.php';
require_once dirname( __DIR__ ) . '/lib/controller/class-trasloco-main-controller.php';

function agency_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$body = json_encode( array(
	'site' => 'https://client-secret.example/',
	'backups' => 12,
	'active_jobs' => 2,
	'failed_jobs' => 1,
	'schedule' => 'daily',
	'database_password' => 'must-not-leak',
) );
$GLOBALS['agency_smoke_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $body );
$GLOBALS['agency_smoke_requests'] = array();
$GLOBALS['agency_smoke_transients'] = array();
$GLOBALS['agency_smoke_ttls'] = array();

agency_assert( count( Trasloco_Agency::sites() ) === 15, 'Agency configuration was not bounded to 15 sites.' );
$controller = ( new ReflectionClass( 'Trasloco_Main_Controller' ) )->newInstanceWithoutConstructor();
$controller->admin_menu();
$agency_menu = array_values( array_filter( $GLOBALS['agency_smoke_submenus'], function ( $menu ) {
	return isset( $menu[4] ) && $menu[4] === 'trasloco_agency';
} ) );
agency_assert( count( $agency_menu ) === 1 && $agency_menu[0][3] === 'manage_options', 'The agency page was not registered as an administrator-only menu.' );
$site = $configured_sites[0];
$health = Trasloco_Agency::inspect( $site );
agency_assert( $health === array( 'status' => 'connected', 'backups' => 12, 'active_jobs' => 2, 'failed_jobs' => 1, 'schedule' => 'daily' ), 'Unexpected or overexposed health payload.' );
agency_assert( count( $GLOBALS['agency_smoke_requests'] ) === 1, 'Expected one remote health request.' );
$request = $GLOBALS['agency_smoke_requests'][0];
agency_assert( $request['url'] === 'https://agency.example/wp-json/trasloco/v1/health', 'Health request used an unexpected URL.' );
agency_assert( $request['args']['timeout'] === 5 && $request['args']['redirection'] === 0 && $request['args']['sslverify'] === true && $request['args']['limit_response_size'] === 16384, 'Remote request limits or TLS/redirect policy are missing.' );
agency_assert( $request['args']['headers']['Authorization'] === 'Basic ' . base64_encode( 'readonly-user:revocable-app-password-secret' ), 'Request did not use the configured Application Password.' );
agency_assert( $GLOBALS['agency_smoke_ttls'] && reset( $GLOBALS['agency_smoke_ttls'] ) === 300, 'Health result was not cached for five minutes.' );
agency_assert( Trasloco_Agency::inspect( $site ) === $health && count( $GLOBALS['agency_smoke_requests'] ) === 1, 'Cached health result triggered a second request.' );
agency_assert( strpos( json_encode( $GLOBALS['agency_smoke_transients'] ), 'revocable-app-password-secret' ) === false, 'A credential was stored in the transient payload.' );

$invalid_sites = array(
	array( 'url' => 'http://agency.example/', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://user:pass@agency.example/', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://agency.example/?token=x', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://agency.example/#fragment', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://agency.example:8443/', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://127.0.0.1/', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://agency.internal/', 'user' => 'u', 'password' => 'p' ),
	array( 'url' => 'https://agency.example/', 'user' => array( 'u' ), 'password' => 'p' ),
	array( 'url' => 'https://agency.example/', 'user' => 'user:unexpected', 'password' => 'p' ),
	array( 'url' => 'https://agency.example/', 'user' => 'u', 'password' => "password\nforged-header" ),
);
foreach ( $invalid_sites as $invalid_site ) {
	agency_assert( Trasloco_Agency::inspect( $invalid_site )['status'] === 'invalid', 'Unsafe or malformed configuration was accepted.' );
}
agency_assert( count( $GLOBALS['agency_smoke_requests'] ) === 1, 'Invalid URL configuration sent credentials over HTTP.' );

$not_checked = $site;
$not_checked['user'] = 'not-checked-user';
agency_assert( Trasloco_Agency::inspect( $not_checked, 0 )['status'] === 'not_checked', 'The request budget did not stop a not-yet-cached site check.' );
agency_assert( count( $GLOBALS['agency_smoke_requests'] ) === 1, 'An exhausted page budget started another remote request.' );

$unauthorized = $site;
$unauthorized['user'] = 'another-user';
$GLOBALS['agency_smoke_response'] = array( 'response' => array( 'code' => 401 ), 'body' => 'denied' );
agency_assert( Trasloco_Agency::inspect( $unauthorized )['status'] === 'unauthorized', 'Unauthorized response was not represented safely.' );

$malformed = $site;
$malformed['user'] = 'malformed-user';
$GLOBALS['agency_smoke_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"backups":-1,"active_jobs":0,"failed_jobs":0,"schedule":"daily"}' );
agency_assert( Trasloco_Agency::inspect( $malformed )['status'] === 'invalid', 'Malformed health response was accepted.' );

$unbounded = $site;
$unbounded['user'] = 'unbounded-user';
$GLOBALS['agency_smoke_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"backups":"999999999999999999999999","active_jobs":0,"failed_jobs":0,"schedule":"daily"}' );
agency_assert( Trasloco_Agency::inspect( $unbounded )['status'] === 'invalid', 'An unbounded numeric response was accepted.' );

$oversized = $site;
$oversized['user'] = 'oversized-user';
$GLOBALS['agency_smoke_response'] = array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'x', 16385 ) );
agency_assert( Trasloco_Agency::inspect( $oversized )['status'] === 'invalid', 'Oversized health response was accepted.' );

$GLOBALS['agency_smoke_admin'] = false;
try {
	Trasloco_Agency::page();
	throw new RuntimeException( 'Agency page was not protected by the administrator capability.' );
} catch ( RuntimeException $error ) {
	if ( $error->getMessage() !== 'You are not allowed to view this page.' ) {
		throw $error;
	}
}

$GLOBALS['agency_smoke_admin'] = true;
$GLOBALS['agency_smoke_response'] = array( 'response' => array( 'code' => 200 ), 'body' => $body );
Trasloco_Agency::page();
agency_assert( substr_count( Trasloco_Template::$html, '<tr>' ) === 16, 'Agency page did not render all bounded sites.' );
agency_assert( strpos( Trasloco_Template::$html, '&lt;script&gt;Agency&lt;/script&gt;' ) !== false, 'Site name was not escaped in the dashboard.' );
agency_assert( strpos( Trasloco_Template::$html, '<script>Agency</script>' ) === false, 'Unescaped site name reached the dashboard.' );
agency_assert( strpos( Trasloco_Template::$html, 'revocable-app-password-secret' ) === false && strpos( Trasloco_Template::$html, 'client-secret.example' ) === false && strpos( Trasloco_Template::$html, 'must-not-leak' ) === false, 'Credentials or private remote health data were rendered.' );

echo "Read-only agency monitoring security checks passed.\n";
