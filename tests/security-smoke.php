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

// Regression for on-disk symlinks redirecting an otherwise safe archive path.
require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-extractor.php';
$root = sys_get_temp_dir() . '/trasloco-symlink-' . bin2hex( random_bytes( 8 ) );
$destination = $root . '/destination';
$outside = $root . '/outside';
mkdir( $destination . '/uploads', 0700, true );
mkdir( $outside, 0700, true );
$crafted = $root . '/attack.wpress';
$header = pack( 'a255a14a12a4096', 'target.txt', '5', '0', 'uploads/link' );
file_put_contents( $crafted, $header . 'hello' . str_repeat( chr( 0 ), 4377 ) );
if ( function_exists( 'symlink' ) && @symlink( $outside, $destination . '/uploads/link' ) ) {
    $extract = new Trasloco_Extractor( $crafted );
    try {
        $extract->extract_one_file_to( $destination );
        ensure( false, 'symlink directory was followed during extraction' );
    } catch ( Trasloco_Archive_Exception $expected ) {
        ensure( ! is_file( $outside . '/target.txt' ), 'no outside file created by archive' );
    } finally {
        $extract->close();
    }
    $extract = new Trasloco_Extractor( $crafted );
    try {
        $extract->extract_by_files_array( $destination, array( 'uploads/link/target.txt' ) );
        ensure( false, 'symlink directory was followed during selective extraction' );
    } catch ( Trasloco_Archive_Exception $expected ) {
        ensure( ! is_file( $outside . '/target.txt' ), 'no outside file created by selective extraction' );
    } finally {
        $extract->close();
    }
    unlink( $destination . '/uploads/link' );
}
unlink( $crafted );
rmdir( $destination . '/uploads' );
rmdir( $destination );
rmdir( $outside );
rmdir( $root );

require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-integrity.php';
$integrity_archive = tempnam( sys_get_temp_dir(), 'trasloco-integrity-' );
file_put_contents( $integrity_archive, 'Original backup bytes' );
$missing_manifest = Trasloco_Integrity::verify( $integrity_archive );
ensure( $missing_manifest['status'] === 'unverified', 'legacy backups have an explicit unverified status' );
$manifest_file = Trasloco_Integrity::create( $integrity_archive );
ensure( is_file( $manifest_file ), 'manifest saved' );
ensure( Trasloco_Integrity::verify( $integrity_archive )['status'] === 'verified', 'checksum valid' );
file_put_contents( $integrity_archive, 'original backup bytes' );
ensure( Trasloco_Integrity::verify( $integrity_archive )['status'] === 'corrupt', 'same-size tampering detected' );
file_put_contents( $integrity_archive, 'short' );
ensure( Trasloco_Integrity::verify( $integrity_archive )['status'] === 'corrupt', 'truncation detected' );
file_put_contents( $manifest_file, '{"manifest_version":2}' );
ensure( Trasloco_Integrity::verify( $integrity_archive )['status'] === 'invalid', 'unsupported manifest rejected' );
unlink( $integrity_archive );
unlink( $manifest_file );



// Version 2 is opt-in and preserves the existing v1 format. Chunk digests
// detect corruption independently and are suitable for bounded workers.
$chunk_archive = tempnam( sys_get_temp_dir(), 'trasloco-chunk-' );
file_put_contents( $chunk_archive, str_repeat( 'A', Trasloco_Integrity::CHUNK_BYTES ) . str_repeat( 'B', 600 ) );
$chunk_manifest = Trasloco_Integrity::create_chunked( $chunk_archive );
$data = Trasloco_Integrity::chunked_manifest( $chunk_archive );
ensure( $data['manifest_version'] === 2 && count( $data['chunks'] ) === 2, 'v2 manifest with chunk digests' );
ensure( Trasloco_Integrity::verify_chunk( $chunk_archive, $data, 0 ), 'first chunk valid' );
ensure( Trasloco_Integrity::verify_chunk( $chunk_archive, $data, 1 ), 'final partial chunk valid' );
ensure( Trasloco_Integrity::verify( $chunk_archive )['status'] === 'verified', 'v2 archive verified' );
$handle = fopen( $chunk_archive, 'r+b' );
fseek( $handle, Trasloco_Integrity::CHUNK_BYTES + 10 );
fwrite( $handle, 'X' );
fclose( $handle );
ensure( ! Trasloco_Integrity::verify_chunk( $chunk_archive, $data, 1 ), 'same-size chunk tampering rejected' );
ensure( Trasloco_Integrity::verify( $chunk_archive )['status'] === 'corrupt', 'v2 integrity rejects changed bytes' );
unlink( $chunk_archive );
unlink( $chunk_manifest );

require dirname( __DIR__ ) . '/lib/model/class-trasloco-encryption.php';
if ( function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) ) {
    $source = tempnam( sys_get_temp_dir(), 'tr-plain-' );
    file_put_contents( $source, str_repeat( "hello world", 10000 ) );
    $encrypted = $source . '.enc';
    $decrypted = $source . '.restored';
    Trasloco_Encryption::encrypt( $source, $encrypted, 'passphrase-with-20chars' );
    Trasloco_Encryption::decrypt( $encrypted, $decrypted, 'passphrase-with-20chars' );
    ensure( hash_file( 'sha256', $source ) === hash_file( 'sha256', $decrypted ), 'encrypted round trip' );
    unlink( $decrypted );
    try {
        Trasloco_Encryption::decrypt( $encrypted, $decrypted, 'definitely-wrong-passphrase' );
        ensure( false, 'wrong password accepted' );
    } catch ( RuntimeException $expected ) {
        ensure( ! file_exists( $decrypted ), 'wrong password left plaintext behind' );
    }
    $tampered = file_get_contents( $encrypted );
    $tampered[80] = chr( ord( $tampered[80] ) ^ 1 );
    file_put_contents( $encrypted, $tampered );
    try {
        Trasloco_Encryption::decrypt( $encrypted, $decrypted, 'passphrase-with-20chars' );
        ensure( false, 'tampered archive decrypted' );
    } catch ( RuntimeException $expected ) {
        ensure( ! file_exists( $decrypted ), 'tampering left decrypted content' );
    }
    unlink( $source );
    unlink( $encrypted );
}
// Access rule regression: backup directory must deny ALL files, not only .wpress.
require dirname( __DIR__ ) . '/lib/vendor/core/filesystem/class-trasloco-file.php';
require dirname( __DIR__ ) . '/lib/vendor/core/filesystem/class-trasloco-file-htaccess.php';
$test_access = tempnam( sys_get_temp_dir(), 'trasloco-access-' );
unlink( $test_access );
Trasloco_File_Htaccess::create( $test_access );
$rules = file_get_contents( $test_access );
ensure( strpos( $rules, '# Trasloco private directory' ) !== false, 'Apache private directory marker' );
ensure( strpos( $rules, 'Require all denied' ) !== false, 'Apache 2.4 full deny' );
ensure( strpos( $rules, 'Order allow,deny' ) !== false, 'Apache 2.2 full deny' );
unlink( $test_access );
echo "Security and archive smoke tests passed.\n";


require dirname( __DIR__ ) . '/lib/model/class-trasloco-archive-inspector.php';
// A crafted archive can have a valid EOF and still contain a malicious
// *later* entry. Reject the entire inventory before any destination writes.
$inspect_archive = tempnam( sys_get_temp_dir(), 'trasloco-pref-' );
$ok_header = pack( 'a255a14a12a4096', 'first.txt', '1', '0', 'uploads' );
$bad_header = pack( 'a255a14a12a4096', 'evil.php', '1', '0', 'uploads/../../outside' );
file_put_contents( $inspect_archive, $ok_header . 'A' . $bad_header . 'X' .
    str_repeat( chr( 0 ), 4377 ) );
try {
    Trasloco_Archive_Inspector::inspect( $inspect_archive, 0 );
    ensure( false, 'unsafe late archive entry accepted in preflight' );
} catch ( Trasloco_Archive_Exception $expected ) {}
unlink( $inspect_archive );


require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-recovery.php';
$offline = tempnam( sys_get_temp_dir(), 'tr-offline-' );
$packet = pack( 'a255a14a12a4096', 'package.json', '2', '0', '.' ) . '{}';
$packet .= pack( 'a255a14a12a4096', 'database.sql', '9', '0', '.' ) . 'SELECT 1;';
$packet .= str_repeat( chr( 0 ), 4377 );
file_put_contents( $offline . '.wpress', $packet );
unlink( $offline );
$offline .= '.wpress';
$dest = sys_get_temp_dir() . '/tr-offline-out-' . bin2hex( random_bytes( 8 ) );
$report = Trasloco_Offline_Recovery::extract_metadata( $offline, $dest );
ensure( $report['integrity'] === 'unverified_legacy', 'offline legacy backup is not trusted as verified' );
ensure( file_get_contents( $dest . '/package.json' ) === '{}', 'offline package extraction' );
ensure( file_get_contents( $dest . '/database.sql' ) === 'SELECT 1;', 'offline database extraction' );
ensure( ! is_file( $dest . '/wp-config.php' ), 'offline utility extracts only selected root metadata' );
$content_out = sys_get_temp_dir() . '/tr-stage-out-' . bin2hex( random_bytes( 8 ) );
$stage_archive = sys_get_temp_dir() . '/tr-stage-' . bin2hex( random_bytes( 8 ) ) . '.wpress';
$stage_fixture = pack( 'a255a14a12a4096', 'package.json', '2', '0', '.' ) . '{}';
$stage_fixture .= pack( 'a255a14a12a4096', 'fixture.txt', '4', '0', 'uploads/docs' ) . 'SAFE';
$stage_fixture .= str_repeat( chr( 0 ), 4377 );
file_put_contents( $stage_archive, $stage_fixture );
$staged = Trasloco_Offline_Recovery::stage_content( $stage_archive, $content_out, 4 );
ensure( $staged['files_staged'] === 1 && $staged['bytes_staged'] === 4, 'standalone content staging counted correctly' );
ensure( file_get_contents( $content_out . '/uploads/docs/fixture.txt' ) === 'SAFE', 'content staged without WordPress' );
ensure( ! is_file( $content_out . '/package.json' ), 'metadata not mixed into staged content' );
try {
    Trasloco_Offline_Recovery::stage_content( $stage_archive, $content_out, 4 );
    ensure( false, 'existing staging directory was overwritten' );
} catch ( RuntimeException $expected ) {}
$too_small = sys_get_temp_dir() . '/tr-stage-small-' . bin2hex( random_bytes( 8 ) );
try {
    Trasloco_Offline_Recovery::stage_content( $stage_archive, $too_small, 1 );
    ensure( false, 'content size limit bypassed' );
} catch ( RuntimeException $expected ) {}
ensure( ! file_exists( $too_small ), 'failed content staging cleaned up its partial directory' );
unlink( $content_out . '/uploads/docs/fixture.txt' );
rmdir( $content_out . '/uploads/docs' );
rmdir( $content_out . '/uploads' );
rmdir( $content_out );
unlink( $stage_archive );
unlink( $dest . '/database.sql' );
unlink( $dest . '/package.json' );
rmdir( $dest );
// Offline rescue and live importer must reject the same malformed archive
// entries, including invalid entries that appear AFTER valid metadata.
$valid_root = pack( 'a255a14a12a4096', 'package.json', '2', '0', '.' ) . '{}';
foreach ( array(
    array( 'danger.php', '../outside' ),
    array( '.', 'uploads' ),
    array( "control\x7fname.txt", 'uploads' ),
    array( 'asset.txt', 'uploads/./nested' ),
    array( 'asset.txt', 'uploads//nested' ),
    array( 'asset.txt', 'uploads/../nested' ),
    array( 'asset.txt', 'uploads/' . chr( 0x7f ) . 'nested' ),
    array( 'asset.txt', 'uploads\\..\\outside' ),
) as $malformed ) {
    $bad = $valid_root .
        pack( 'a255a14a12a4096', $malformed[0], '1', '0', $malformed[1] ) . 'X' .
        str_repeat( chr( 0 ), 4377 );
    file_put_contents( $offline, $bad );
    try {
        Trasloco_Offline_Recovery::inspect( $offline );
        ensure( false, 'Offline rescue accepted malformed archive entry: ' . json_encode( $malformed ) );
    } catch ( RuntimeException $expected ) {}
    $unsafe_stage = sys_get_temp_dir() . '/tr-reject-stage-' . bin2hex( random_bytes( 8 ) );
    try {
        Trasloco_Offline_Recovery::stage_content( $offline, $unsafe_stage, 1024 );
        ensure( false, 'Offline staging accepted malformed archive entry' );
    } catch ( RuntimeException $expected ) {
        ensure( ! file_exists( $unsafe_stage ), 'Unsafe offline staging created a partial directory' );
    }
}
unlink( $offline );

// Performance guard: archive inventory must not compute every SHA-256 on page load.
ensure( strpos( file_get_contents( dirname( __DIR__ ) . '/lib/view/backups/index.php' ), 'Trasloco_Integrity::verify(' ) === false,
    'Backup list must not synchronously hash each archive' );


// Restore safety regression: a well-terminated archive is still unsafe if a
// file name is "." or if any path component is "." / empty / control-bearing.
foreach ( array(
    array( '.', 'uploads/docs' ),
    array( "bad\nname.txt", 'uploads/docs' ),
    array( 'safe.txt', 'uploads/./docs' ),
    array( 'safe.txt', 'uploads//docs' ),
    array( 'safe.txt', 'uploads/' . "\t" . 'docs' ),
) as $unsafe ) {
    $crafted = tempnam( sys_get_temp_dir(), 'tr-header-' );
    $payload = pack( 'a255a14a12a4096', $unsafe[0], '1', '0', $unsafe[1] ) . 'X';
    file_put_contents( $crafted, $payload . str_repeat( chr( 0 ), 4377 ) );
    try {
        Trasloco_Archive_Inspector::inspect( $crafted, 0 );
        ensure( false, 'preflight accepted malformed path/header: ' . json_encode( $unsafe ) );
    } catch ( Trasloco_Archive_Exception $expected ) {
        // No destination file writes should happen on a malformed archive.
    } finally {
        @unlink( $crafted );
    }
}

// Restore safety regression: extracting a file from a prematurely truncated
// archive must fail, never report that the declared 12 bytes were copied.
$incomplete = tempnam( sys_get_temp_dir(), 'tr-short-entry-' );
$folder = sys_get_temp_dir() . '/tr-short-extract-' . bin2hex( random_bytes( 8 ) );
mkdir( $folder, 0700 );
file_put_contents( $incomplete,
    pack( 'a255a14a12a4096', 'short.txt', '12', '0', '.' ) . 'A' );
$extractor = new Trasloco_Extractor( $incomplete );
try {
    $extractor->extract_one_file_to( $folder );
    ensure( false, 'extractor treated a truncated archive entry as complete' );
} catch ( Trasloco_Not_Readable_Exception $expected ) {
    // The import must abort rather than silently accepting an incomplete file.
} finally {
    $extractor->close();
    @unlink( $folder . '/short.txt' );
    @rmdir( $folder );
    @unlink( $incomplete );
}


// The same .wpress can contain multiple copies of root package.json, which
// could be interpreted differently by package/database restore stages.
// Refuse ambiguity before a recovery snapshot or destination modifications.
$duplicate_package = tempnam( sys_get_temp_dir(), 'tr-duplicate-root-' );
$package_record = pack( 'a255a14a12a4096', 'package.json', '2', '0', '.' ) . '{}';
file_put_contents( $duplicate_package,
    $package_record . $package_record . str_repeat( chr( 0 ), 4377 ) );
try {
    Trasloco_Archive_Inspector::inspect( $duplicate_package, 0 );
    ensure( false, 'duplicate root restore metadata was accepted' );
} catch ( Trasloco_Archive_Exception $expected ) {
    // Expected: multiple package.json entries cannot be restored safely.
} finally {
    @unlink( $duplicate_package );
}


// A symlinked directory must not become a child of the content traversal;
// otherwise backup jobs can escape wp-content and include secrets.
require dirname( __DIR__ ) . '/lib/vendor/core/iterator/class-trasloco-recursive-directory-iterator.php';
$outside = sys_get_temp_dir() . '/trasloco-outside-' . bin2hex( random_bytes( 8 ) );
$inside = sys_get_temp_dir() . '/trasloco-inside-' . bin2hex( random_bytes( 8 ) );
mkdir( $inside, 0700 );
mkdir( $outside, 0700 );
file_put_contents( $outside . '/secret.txt', 'not part of wp-content' );
symlink( $outside, $inside . '/external' );
$directory = new Trasloco_Recursive_Directory_Iterator( $inside );
foreach ( $directory as $entry ) {
    if ( $entry->isLink() ) {
        ensure( $directory->hasChildren( true ) === false, 'export iterator follows external directory symlinks' );
    }
}
unlink( $inside . '/external' );
unlink( $outside . '/secret.txt' );
rmdir( $inside );
rmdir( $outside );


// A portable "complete" backup must not silently omit symlinked plugins,
// themes or media; their dereference may also leak outside the content root.
require dirname( __DIR__ ) . '/lib/vendor/core/filter/class-trasloco-recursive-newline-filter.php';
$linked_root = sys_get_temp_dir() . '/tr-linked-root-' . bin2hex( random_bytes( 8 ) );
$linked_target = sys_get_temp_dir() . '/tr-linked-target-' . bin2hex( random_bytes( 8 ) );
mkdir( $linked_root, 0700 );
mkdir( $linked_target, 0700 );
symlink( $linked_target, $linked_root . '/linked-theme' );
try {
    $filter = new Trasloco_Recursive_Newline_Filter(
        new Trasloco_Recursive_Directory_Iterator( $linked_root ) );
    foreach ( $filter as $entry ) {
        // A symlink must throw before a complete inventory is reported.
    }
    ensure( false, 'linked content was silently omitted from export' );
} catch ( Trasloco_Archive_Exception $expected ) {
} finally {
    unlink( $linked_root . '/linked-theme' );
    rmdir( $linked_root );
    rmdir( $linked_target );
}


// A source file removed between export enumeration and compression must
// abort the backup instead of silently producing a valid-looking archive.
require dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-compressor.php';
$missing_source = sys_get_temp_dir() . '/trasloco-removed-' . bin2hex( random_bytes( 8 ) );
$compression_target = tempnam( sys_get_temp_dir(), 'tr-compress-miss-' );
$compressor = new Trasloco_Compressor( $compression_target );
try {
    $compressor->add_file( $missing_source, 'uploads/disappeared.txt' );
    ensure( false, 'compressor accepted a missing source file' );
} catch ( Trasloco_Not_Readable_Exception $expected ) {
} finally {
    $compressor->close();
    @unlink( $compression_target );
}


// Mandatory restore inputs must be checked before a safety snapshot or file
// replacement. An archive may be structurally valid but lack database.sql.
require dirname( __DIR__ ) . '/lib/model/import/class-trasloco-import-preflight.php';
$make = function ( $include_database, $no_database ) {
    $package = json_encode( array( 'NoDatabase' => $no_database ) );
    $body = pack( 'a255a14a12a4096', 'package.json', (string) strlen( $package ), '0', '.' ) . $package;
    if ( $include_database ) {
        $body .= pack( 'a255a14a12a4096', 'database.sql', '9', '0', '.' ) . 'SELECT 1;';
    }
    $body .= pack( 'a255a14a12a4096', 'hello.txt', '5', '0', 'uploads' ) . 'hello';
    $file = sys_get_temp_dir() . '/tr-missing-sql-' . bin2hex( random_bytes( 8 ) ) . '.wpress';
    file_put_contents( $file, $body . str_repeat( chr( 0 ), 4377 ) );
    return $file;
};
foreach ( array(
    array( false, false, false ), // missing SQL is fatal for full restore
    array( true, false, true ),   // valid full restore
    array( false, true, true ),   // legitimate files-only backup
    array( true, true, true ),    // conservative backwards compatibility
) as $case ) {
    list( $with_sql, $no_sql, $should_pass ) = $case;
    $fixture = $make( $with_sql, $no_sql );
    $inventory = Trasloco_Archive_Inspector::inspect( $fixture, 0 );
    $passed = false;
    try {
        Trasloco_Import_Preflight::assert_required_payload( $inventory, array( 'NoDatabase' => $no_sql ) );
        $passed = true;
    } catch ( Trasloco_Import_Exception $expected ) {}
    ensure( $passed === $should_pass, 'database presence must match explicit files-only export setting' );
    unlink( $fixture );
}
try {
    Trasloco_Import_Preflight::assert_required_payload(
        array( 'restore_inputs' => array( 'database.sql' => 9 ) ), array( 'NoDatabase' => true )
    );
    ensure( false, 'missing package metadata allowed before destructive restore' );
} catch ( Trasloco_Import_Exception $expected ) {}


// Malformed paths must never be echoed verbatim into logs/error messages
// (NUL bytes, line breaks and terminal escape sequences are attacker-owned).
$bad_log_archive = sys_get_temp_dir() . '/tr-log-safety-' . bin2hex( random_bytes( 8 ) );
$bad_log_header = pack( 'a255a14a12a4096', "dangerous\nname.txt", '1', '0', 'uploads' );
file_put_contents( $bad_log_archive, $bad_log_header . 'X' . str_repeat( chr( 0 ), 4377 ) );
$bad_reader = new Trasloco_Extractor( $bad_log_archive );
try {
    $bad_reader->extract_one_file_to( sys_get_temp_dir() );
    ensure( false, 'control-character archive entry was accepted' );
} catch ( Trasloco_Archive_Exception $expected ) {
    ensure( $expected->getMessage() === 'Unsafe file path in archive.',
        'untrusted archive filenames leaked through exception messages' );
} finally {
    $bad_reader->close();
    unlink( $bad_log_archive );
}


// A recovery receipt survives a replaced WordPress database: no WP functions.
require dirname( __DIR__ ) . '/lib/model/import/class-trasloco-recovery-journal.php';
$journal_dir = sys_get_temp_dir() . '/trasloco-journal-' . bin2hex( random_bytes( 8 ) );
mkdir( $journal_dir, 0700 );
file_put_contents( $journal_dir . '/recovery-safe.wpress', 'fixture' );
file_put_contents( $journal_dir . '/recovery-safe.wpress.manifest.json', '{}' );
$id = Trasloco_Recovery_Journal::begin( $journal_dir, 'incoming.wpress', 'recovery-safe.wpress' );
$records = Trasloco_Recovery_Journal::entries( $journal_dir );
ensure( count( $records ) === 1 && $records[0]['status'] === 'pending', 'journal is pending before import' );
ensure( $records[0]['recovery_archive'] === 'recovery-safe.wpress', 'journal records recovery archive' );
try {
    Trasloco_Recovery_Journal::begin( $journal_dir, '../traversal.wpress', 'recovery-safe.wpress' );
    ensure( false, 'unsafe receipt input accepted' );
} catch ( InvalidArgumentException $expected ) {}
Trasloco_Recovery_Journal::complete( $journal_dir, $id );
ensure( Trasloco_Recovery_Journal::entries( $journal_dir )[0]['status'] === 'completed', 'successful import closes receipt' );
unlink( $journal_dir . '/recovery-safe.wpress' );
unlink( $journal_dir . '/recovery-safe.wpress.manifest.json' );
unlink( $journal_dir . '/.trasloco-recovery-' . $id . '.json' );
rmdir( $journal_dir );
