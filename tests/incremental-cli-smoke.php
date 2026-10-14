<?php
namespace cli {
    class Table {
        public function setHeaders( $headers ) {}
        public function addRow( $row ) {}
        public function display() {}
    }
}
namespace {
    class WP_CLI_Command {}
    class WP_CLI {
        public static function add_command( $name, $class, $args = array() ) {}
        public static function success( $message ) {}
        public static function error( $message ) { throw new RuntimeException( $message ); }
        public static function line( $message ) {}
    }
    function size_format( $size, $decimals = 0 ) { return (string) $size; }

    $root = sys_get_temp_dir() . '/trasloco-incremental-cli-' . bin2hex( random_bytes( 8 ) );
    $backup_dir = $root . '/backups';
    mkdir( $backup_dir, 0700, true );
    define( 'TRASLOCO_BACKUPS_PATH', $backup_dir );
    define( 'TRASLOCO_LIB_PATH', dirname( __DIR__ ) . '/lib' );
    require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
    require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
    require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-backups.php';
    require_once TRASLOCO_LIB_PATH . '/vendor/core/command/class-trasloco-wp-cli-command.php';

    function incremental_cli_assert( $condition, $message ) {
        if ( ! $condition ) { throw new RuntimeException( $message ); }
    }

    try {
        $name = 'cli-fixture.wpress';
        $path = $backup_dir . '/' . $name;
        file_put_contents( $path, str_repeat( 'archive-data-', 12000 ) );
        Trasloco_Integrity::create( $path );
        $command = new Trasloco_WP_CLI_Command();
        $command->incremental( array( 'capture', $name ), array() );
        $command->incremental( array( 'verify', $name ), array() );
        $command->incremental( array( 'list' ), array() );
        $command->incremental( array( 'stats' ), array() );
        $failed = false;
        try { $command->incremental( array( 'compact', $name ), array() ); } catch ( RuntimeException $error ) { $failed = true; }
        incremental_cli_assert( $failed && is_file( $path ), 'WP-CLI compact removed an archive without valid restore structure.' );
        $output = 'cli-restored.wpress';
        $command->incremental( array( 'materialize', $name ), array( 'output' => $output ) );
        incremental_cli_assert( hash_file( 'sha256', $path ) === hash_file( 'sha256', $backup_dir . '/' . $output ),
            'WP-CLI materialize did not reproduce the verified archive.' );
        $command->incremental( array( 'prune' ), array( 'keep' => $name ) );
        incremental_cli_assert( count( ( new Trasloco_Incremental_Store( $backup_dir . '/.trasloco-incremental' ) )->list_snapshots() ) === 1,
            'WP-CLI prune removed the retained snapshot.' );
        $retained = Trasloco_Backups::incremental_retention_names(
            array( array( 'filename' => 'newer.wpress', 'mtime' => 30 ), array( 'filename' => 'older.wpress', 'mtime' => 10 ) ),
            array( array( 'archive' => 'compacted.wpress', 'mtime' => 20 ) ), 2
        );
        incremental_cli_assert( $retained === array( 'newer.wpress', 'compacted.wpress' ),
            'Incremental retention did not count snapshot-only catalogs alongside full archives.' );

        $failed = false;
        try { $command->incremental( array( 'materialize', $name ), array( 'output' => '../outside.wpress' ) ); }
        catch ( RuntimeException $error ) { $failed = true; }
        incremental_cli_assert( $failed && ! file_exists( dirname( $backup_dir ) . '/outside.wpress' ),
            'WP-CLI accepted an output path outside the backup directory.' );

        echo "Incremental WP-CLI capture, verification, reconstruction, listing and retention tests passed.\n";
    } finally {
        if ( is_dir( $root ) ) {
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $iterator as $item ) { $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); }
            rmdir( $root );
        }
    }
}
