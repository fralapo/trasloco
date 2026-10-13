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
/**
 * Explicit destinations let operators rehearse with predictable disk space.
 * Resolve the parent directory first, so an existing symlink parent cannot
 * redirect the output after validating the chosen path.
 *
 * No WordPress dependencies or filesystem operations occur during parsing.
 */
function trasloco_rescue_arguments( $args ) {
    if ( count( $args ) < 2 || count( $args ) > 4 || ! is_string( $args[1] ) ) {
        throw new InvalidArgumentException( 'Invalid number of arguments.' );
    }
    $with_content = false;
    $chosen_directory = null;
    foreach ( array_slice( $args, 2 ) as $option ) {
        if ( $option === '--content' && ! $with_content ) {
            $with_content = true;
            continue;
        }
        if ( strpos( $option, '--output=' ) === 0 && $chosen_directory === null ) {
            $path = substr( $option, strlen( '--output=' ) );
            // Only absolute destinations; never accidentally stage inside a
            // WordPress site because the shell working directory changed.
            if ( $path === '' || ! preg_match( '#^(?:/|[A-Za-z]:[\\\\/])#', $path ) ) {
                throw new InvalidArgumentException( 'The --output directory must be an absolute path.' );
            }
            $leaf = basename( rtrim( $path, '/\\\\' ) );
            if ( $leaf === '' || $leaf === '.' || $leaf === '..' ||
                ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}$/D', $leaf ) ) {
                throw new InvalidArgumentException( 'Invalid recovery output directory name.' );
            }
            $parent = realpath( dirname( $path ) );
            if ( $parent === false || ! is_dir( $parent ) || ! is_writable( $parent ) ) {
                throw new InvalidArgumentException( 'Recovery output parent must exist and be writable.' );
            }
            $chosen_directory = $parent . DIRECTORY_SEPARATOR . $leaf;
            if ( file_exists( $chosen_directory ) || is_link( $chosen_directory ) ) {
                throw new InvalidArgumentException( 'Recovery output already exists and cannot be overwritten.' );
            }
            continue;
        }
        throw new InvalidArgumentException( 'Unknown or duplicate rescue option.' );
    }
    return array( $chosen_directory, $with_content );
}
try {
    list( $directory, $with_content ) = trasloco_rescue_arguments( $argv );
    if ( $directory === null ) {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-rescue-' . bin2hex( random_bytes( 16 ) );
    }
    $report = Trasloco_Offline_Recovery::extract_metadata( $argv[1], $directory );
    fwrite( STDOUT, "Recovery metadata saved in: {$directory}\n" );
    fwrite( STDOUT, "Archive integrity: {$report['integrity']}\n" );
    if ( $with_content ) {
        $staged = Trasloco_Offline_Recovery::stage_content( $argv[1], $directory . '/content' );
        fwrite( STDOUT, "Private WordPress files staged: {$staged['files_staged']} ({$staged['bytes_staged']} bytes)\n" );
    }
    fwrite( STDOUT, "This is manual recovery material, NOT a restored site.\n" );
    fwrite( STDOUT, "Ensure this directory is not served by any web server.\n" );
    fwrite( STDOUT, "Keep database.sql and package.json private. Do not execute SQL without checking table prefixes and backups.\n" );
} catch ( InvalidArgumentException $e ) {
    fwrite( STDERR, "Usage: php tools/rescue-extract.php /private/site.wpress [--content] [--output=/private/new-directory]\n" );
    fwrite( STDERR, "Invalid arguments: {$e->getMessage()}\n" );
    exit( 2 );
} catch ( Throwable $e ) {
    fwrite( STDERR, "Recovery extraction failed: {$e->getMessage()}\n" );
    exit( 1 );
}
