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
if ( ! in_array( $argc, array( 2, 3 ), true ) ||
    ! is_string( $argv[1] ) ||
    ( $argc === 3 && $argv[2] !== '--content' ) ) {
    fwrite( STDERR, "Usage: php tools/rescue-extract.php /private/site.wpress [--content]\n" );
    exit( 2 );
}
try {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-rescue-' . bin2hex( random_bytes( 16 ) );
    $report = Trasloco_Offline_Recovery::extract_metadata( $argv[1], $directory );
    fwrite( STDOUT, "Recovery metadata saved in: {$directory}\n" );
    fwrite( STDOUT, "Archive integrity: {$report['integrity']}\n" );
    if ( $argc === 3 ) {
        $staged = Trasloco_Offline_Recovery::stage_content( $argv[1], $directory . '/content' );
        fwrite( STDOUT, "Private WordPress files staged: {$staged['files_staged']} ({$staged['bytes_staged']} bytes)\n" );
    }
    fwrite( STDOUT, "This is manual recovery material, NOT a restored site.\n" );
    fwrite( STDOUT, "Keep database.sql and package.json private. Do not execute SQL without checking table prefixes and backups.\n" );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Recovery extraction failed: {$e->getMessage()}\n" );
    exit( 1 );
}
