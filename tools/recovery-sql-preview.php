<?php
/** Read-only table-operation inventory; never executes database.sql. */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
if ( $argc < 2 || $argc > 3 || ( isset( $argv[2] ) && $argv[2] !== '--json' ) ) {
    fwrite( STDERR, "Usage: php tools/recovery-sql-preview.php /private/recovery.wpress [--json]\n" );
    exit( 2 );
}
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-offline-sql-preview.php';
try {
    $result = Trasloco_Offline_SQL_Preview::preview( $argv[1] );
    if ( isset( $argv[2] ) ) {
        echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    } else {
        printf( "Archive: %s\nDatabase SQL: %s\nTables found: %d\nSQL bytes scanned: %d\n",
            $result['integrity'], $result['database_sql'], $result['tables_seen'], $result['sql_bytes_scanned'] );
        foreach ( $result['tables'] as $table => $counts ) {
            printf( "%s | drop %d, create %d, insert %d, replace %d, alter %d, truncate %d\n",
                $table, $counts['drop'], $counts['create'], $counts['insert'],
                $counts['replace'], $counts['alter'], $counts['truncate'] );
        }
        echo $result['warning'] . "\n";
    }
} catch ( Throwable $e ) {
    fwrite( STDERR, "SQL preview failed: " . $e->getMessage() . "\n" );
    exit( 1 );
}
