<?php
/**
 * Independent, operator-controlled wp-content file recovery.
 *
 * php tools/recovery-files.php prepare /private/recovery.wpress /site/wp-content /private/new-workspace
 * php tools/recovery-files.php apply /private/new-workspace /site/wp-content --site-offline-confirmed
 *
 * Never deletes unknown files, imports SQL, or proves atomic site rollback.
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-file-recovery.php';
try {
    if ( $argc === 5 && $argv[1] === 'prepare' ) {
        $report = Trasloco_Offline_File_Recovery::prepare( $argv[2], $argv[3], $argv[4] );
    } elseif ( $argc === 5 && $argv[1] === 'apply' &&
               $argv[4] === '--site-offline-confirmed' ) {
        $report = Trasloco_Offline_File_Recovery::apply( $argv[2], $argv[3] );
    } else {
        fwrite( STDERR, "Usage:\n  php tools/recovery-files.php prepare /private/recovery.wpress /site/wp-content /private/new-workspace\n  php tools/recovery-files.php apply /private/workspace /site/wp-content --site-offline-confirmed\n" );
        exit( 2 );
    }
    echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $e ) {
    fwrite( STDERR, "File recovery failed: " . $e->getMessage() . "\n" );
    exit( 1 );
}
