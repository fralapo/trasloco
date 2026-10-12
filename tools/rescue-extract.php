<?php
/**
 * Run only in CLI. Works even if WordPress and MySQL cannot start:
 *
 * php tools/rescue-extract.php /private/backups/site.wpress
 *
 * Does NOT execute SQL or modify an existing site.
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-recovery.php';
if ( $argc !== 2 || ! is_string( $argv[1] ) ) {
    fwrite( STDERR, "Usage: php tools/rescue-extract.php /private/site.wpress\n" );
    exit( 2 );
}
try {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-rescue-' . bin2hex( random_bytes( 16 ) );
    $report = Trasloco_Offline_Recovery::extract_metadata( $argv[1], $directory );
    fwrite( STDOUT, "Recovery metadata saved in: {$directory}\n" );
    fwrite( STDOUT, "Archive integrity: {$report['integrity']}\n" );
    fwrite( STDOUT, "This is manual recovery material, NOT a restored site.\n" );
    fwrite( STDOUT, "Keep database.sql and package.json private. Do not execute SQL without checking table prefixes and backups.\n" );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Recovery extraction failed: {$e->getMessage()}\n" );
    exit( 1 );
}
