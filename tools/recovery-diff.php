<?php
/**
 * Read-only file-level disaster recovery comparison, no WordPress bootstrap:
 * php tools/recovery-diff.php /private/recovery.wpress /site/wp-content [--json]
 *
 * Never changes the site, runs SQL, or deletes files. Extra files are not
 * enumerated; an independent recovery is still required for full rollback.
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
if ( ! in_array( $argc, array( 3, 4 ), true ) ||
     ( $argc === 4 && $argv[3] !== '--json' ) ) {
    fwrite( STDERR, "Usage: php tools/recovery-diff.php /private/recovery.wpress /site/wp-content [--json]\n" );
    exit( 2 );
}
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-recovery-diff.php';
try {
    $result = Trasloco_Offline_Recovery_Diff::compare( $argv[1], $argv[2] );
    if ( $argc === 4 ) {
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    } else {
        printf( "Archive: %s\nFiles compared: %d\nIdentical: %d\nModified: %d\nMissing: %d\nUnsafe: %d\n",
            $result['integrity'], $result['files_compared'], $result['identical'],
            $result['modified'], $result['missing'], $result['unsafe_target']
        );
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
