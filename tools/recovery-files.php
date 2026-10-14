<?php
/**
 * Independent, operator-controlled wp-content file recovery.
 *
 * php tools/recovery-files.php prepare /private/recovery.wpress /site/wp-content /private/new-workspace [--include-untracked]
 * php tools/recovery-files.php review /private/workspace
 * php tools/recovery-files.php apply /private/workspace /site/wp-content --site-offline-confirmed [--approve-untracked=<sha256>]
 * php tools/recovery-files.php restore-quarantine /private/workspace /site/wp-content --approve-untracked=<sha256>
 *
 * Never automatically deletes unknown files. Optional archive-absent files
 * require an exact reviewed fingerprint and are retained in quarantine.
 * This tool does not import SQL or prove atomic site rollback.
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-file-recovery.php';
try {
    if ( $argc === 5 && $argv[1] === 'prepare' ) {
        $report = Trasloco_Offline_File_Recovery::prepare( $argv[2], $argv[3], $argv[4] );
    } elseif ( $argc === 6 && $argv[1] === 'prepare' && $argv[5] === '--include-untracked' ) {
        $report = Trasloco_Offline_File_Recovery::prepare( $argv[2], $argv[3], $argv[4],
            Trasloco_Offline_File_Recovery::MAX_BYTES, true );
    } elseif ( $argc === 3 && $argv[1] === 'review' ) {
        $report = Trasloco_Offline_File_Recovery::review( $argv[2] );
    } elseif ( $argc === 5 && $argv[1] === 'apply' &&
               $argv[4] === '--site-offline-confirmed' ) {
        $report = Trasloco_Offline_File_Recovery::apply( $argv[2], $argv[3] );
    } elseif ( $argc === 6 && $argv[1] === 'apply' &&
               $argv[4] === '--site-offline-confirmed' &&
               preg_match( '/^--approve-untracked=([a-f0-9]{64})$/D', $argv[5], $match ) ) {
        $report = Trasloco_Offline_File_Recovery::apply( $argv[2], $argv[3], $match[1] );
    } elseif ( $argc === 5 && $argv[1] === 'restore-quarantine' &&
               preg_match( '/^--approve-untracked=([a-f0-9]{64})$/D', $argv[4], $match ) ) {
        $report = Trasloco_Offline_File_Recovery::restore_quarantine( $argv[2], $argv[3], $match[1] );
    } else {
        fwrite( STDERR, "Usage:\n  php tools/recovery-files.php prepare /private/recovery.wpress /site/wp-content /private/new-workspace [--include-untracked]\n  php tools/recovery-files.php review /private/workspace\n  php tools/recovery-files.php apply /private/workspace /site/wp-content --site-offline-confirmed [--approve-untracked=<sha256>]\n  php tools/recovery-files.php restore-quarantine /private/workspace /site/wp-content --approve-untracked=<sha256>\n" );
        exit( 2 );
    }
    echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $e ) {
    fwrite( STDERR, "File recovery failed: " . $e->getMessage() . "\n" );
    exit( 1 );
}
