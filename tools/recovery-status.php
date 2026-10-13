<?php
/**
 * List independent recovery receipts after partial SQL restore failures.
 * PHP CLI only: no WordPress bootstrap, no MySQL access, no site writes.
 *
 * php tools/recovery-status.php /absolute/path/to/trasloco-backups [--json]
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 1 );
}
if ( ! in_array( $argc, array( 2, 3 ), true ) ||
     ! is_string( $argv[1] ) || $argv[1] === '' ||
     $argv[1][0] !== DIRECTORY_SEPARATOR ||
     ( $argc === 3 && $argv[2] !== '--json' ) ) {
    fwrite( STDERR, "Usage: php tools/recovery-status.php /absolute/private/backup-directory [--json]\n" );
    exit( 2 );
}
require_once dirname( __DIR__ ) . '/lib/model/import/class-trasloco-recovery-journal.php';
try {
    $records = Trasloco_Recovery_Journal::entries( $argv[1], 100 );
    if ( $argc === 3 ) {
        echo json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    } elseif ( ! $records ) {
        echo "No valid recovery receipts were found.\n";
    } else {
        foreach ( $records as $record ) {
            printf( "%s | %s | %s | %s\n",
                $record['created_at'], $record['status'],
                $record['source_archive'], $record['recovery_archive']
            );
        }
    }
    fwrite( STDERR, "A pending receipt means an import did not confirm completion; it is not an automatic rollback.\n" );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Cannot inspect recovery receipts: " . $e->getMessage() . "\n" );
    exit( 1 );
}
