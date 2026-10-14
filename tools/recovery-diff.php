<?php
/**
 * Read-only file-level disaster recovery comparison, no WordPress bootstrap:
 * php tools/recovery-diff.php /private/recovery.wpress /site/wp-content [--json]
 *     [--scan-untracked] [--exclude=uploads/cache]...
 *
 * Never changes the site, runs SQL, or deletes files. Extra files are not
 * enumerated unless --scan-untracked is requested. The extra inventory
 * never implies those files are safe to delete.
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
$flags = array_slice( $argv, 3 );
$excludes = array();
$options = array();
foreach ( $flags as $flag ) {
    if ( is_string( $flag ) && substr( $flag, 0, 10 ) === '--exclude=' ) {
        $excludes[] = substr( $flag, 10 );
    } else {
        $options[] = $flag;
    }
}
if ( $argc < 3 || $argc > 105 || count( $flags ) !== count( array_unique( $flags ) ) ||
     array_diff( $options, array( '--json', '--scan-untracked' ) ) || count( $excludes ) > 100 ) {
    fwrite( STDERR, "Usage: php tools/recovery-diff.php /private/recovery.wpress /site/wp-content [--json] [--scan-untracked] [--exclude=uploads/cache]...\n" );
    exit( 2 );
}
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-recovery-diff.php';
try {
    $result = Trasloco_Offline_Recovery_Diff::compare( $argv[1], $argv[2],
        Trasloco_Offline_Recovery_Diff::DEFAULT_MAX_BYTES,
        in_array( '--scan-untracked', $options, true ), $excludes );
    if ( in_array( '--json', $options, true ) ) {
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    } else {
        printf( "Archive: %s\nFiles compared: %d\nExcluded: %d\nIdentical: %d\nModified: %d\nMissing: %d\nUnsafe: %d\n",
            $result['integrity'], $result['files_compared'], $result['files_excluded'], $result['identical'],
            $result['modified'], $result['missing'], $result['unsafe_target']
        );
        foreach ( $result['exclusions'] as $exclude ) {
            printf( "Excluded path: %s\n", $exclude );
        }
        if ( $result['untracked_scan'] === 'completed' ) {
            printf( "Not in snapshot: %d\nUnsafe untracked: %d\nDestination entries scanned: %d\n",
                $result['not_in_snapshot'], $result['unsafe_untracked'], $result['destination_entries_scanned'] );
            foreach ( $result['untracked_sample'] as $item ) {
                printf( "%s | %s\n", $item['state'], $item['relative_path'] );
            }
        }
        foreach ( $result['sample'] as $item ) {
            printf( "%s | %s\n", $item['state'], $item['relative_path'] );
        }
        echo $result['warning'] . "\n";
    }
} catch ( Throwable $e ) {
    // No absolute filesystem paths should be emitted by known validation.
    fwrite( STDERR, "Recovery comparison failed: " . $e->getMessage() . "\n" );
    exit( 1 );
}
