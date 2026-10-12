<?php
/**
 * Independent disaster-recovery decryptor for .wpress.enc archives.
 *
 * Does not bootstrap WordPress or connect to a database. No credentials are
 * accepted as CLI arguments, and the destination must not already exist.
 *
 * TRASLOCO_ARCHIVE_PASSPHRASE=... php tools/rescue-decrypt.php \
 *     /secure/site.wpress.enc /secure/site-restored.wpress
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
if ( $argc !== 3 || ! is_string( $argv[1] ) || ! is_string( $argv[2] ) ||
    ! preg_match( '/\\.wpress\\.enc$/D', $argv[1] ) ||
    ! preg_match( '/\\.wpress$/D', $argv[2] ) ) {
    fwrite( STDERR, "Usage: php tools/rescue-decrypt.php source.wpress.enc new-output.wpress\n" );
    exit( 2 );
}
$passphrase = getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
    fwrite( STDERR, "Set TRASLOCO_ARCHIVE_PASSPHRASE to the original passphrase (12+ characters).\n" );
    exit( 2 );
}
require_once dirname( __DIR__ ) . '/lib/model/class-trasloco-encryption.php';
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-recovery.php';
try {
    if ( is_link( $argv[1] ) || file_exists( $argv[2] ) || is_link( $argv[2] ) ) {
        throw new RuntimeException( 'Refusing a symlink or existing output.' );
    }
    Trasloco_Encryption::decrypt( $argv[1], $argv[2], $passphrase );
    try {
        $report = Trasloco_Offline_Recovery::inspect( $argv[2] );
    } catch ( Throwable $e ) {
        @unlink( $argv[2] );
        throw new RuntimeException( 'The decrypted data is not a valid .wpress backup.' );
    }
    fwrite( STDOUT, "Successfully decrypted and inspected archive (not restored).\n" );
    fwrite( STDOUT, "Entries: {$report['entries']}\n" );
    fwrite( STDOUT, "Output: {$argv[2]}\n" );
    fwrite( STDOUT, "Keep this file private. Recover to an isolated environment before affecting production.\n" );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Independent decryption failed: {$e->getMessage()}\n" );
    exit( 1 );
}
