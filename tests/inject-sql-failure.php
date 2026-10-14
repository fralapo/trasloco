<?php
/**
 * Integration fixture. Copy a genuine .wpress archive while appending either
 * a failing or deliberately slow SQL statement to its root database.sql
 * entry. Headers and trailer remain valid so tests reach the SQL importer.
 */
if ( PHP_SAPI !== 'cli' || ( $argc !== 3 && $argc !== 4 ) ) {
    fwrite( STDERR, "Usage: php tests/inject-sql-failure.php INPUT.wpress OUTPUT.wpress [failure|sleep]\n" );
    exit( 2 );
}
$source = $argv[1];
$target = $argv[2];
$mode = $argc === 4 ? $argv[3] : 'failure';
if ( ! in_array( $mode, array( 'failure', 'sleep' ), true ) ) {
    fwrite( STDERR, "Unsupported SQL fixture mode.\n" );
    exit( 2 );
}
if ( $source === $target || ! is_file( $source ) || ! is_readable( $source ) ||
     is_link( $source ) || file_exists( $target ) || is_link( $target ) ) {
    fwrite( STDERR, "Unsafe SQL failure fixture input or output.\n" );
    exit( 1 );
}
$input = @fopen( $source, 'rb' );
$output = @fopen( $target, 'xb' );
if ( ! $input || ! $output ) {
    if ( $input ) { fclose( $input ); }
    if ( $output ) { fclose( $output ); }
    fwrite( STDERR, "Cannot open SQL failure fixture.\n" );
    exit( 1 );
}
function tr_fixture_write( $stream, $data ) {
    $at = 0;
    while ( $at < strlen( $data ) ) {
        $n = fwrite( $stream, substr( $data, $at ) );
        if ( $n === false || $n === 0 ) {
            throw new RuntimeException( 'SQL fixture write failed.' );
        }
        $at += $n;
    }
}
$header_bytes = 4377;
$size = filesize( $source );
$cursor = 0;
$injected = 0;
$extra = $mode === 'sleep' ? "\nSELECT SLEEP(30);\n" : "\nSELECT * FROM trasloco_ci_forced_sql_failure_2026;\n";
try {
    while ( $cursor < $size - $header_bytes ) {
        if ( fseek( $input, $cursor, SEEK_SET ) !== 0 ) {
            throw new RuntimeException( 'Cannot seek archive block.' );
        }
        $header = fread( $input, $header_bytes );
        if ( ! is_string( $header ) || strlen( $header ) !== $header_bytes ) {
            throw new RuntimeException( 'Invalid archive header.' );
        }
        $name = trim( substr( $header, 0, 255 ), "\0 " );
        $digits = trim( substr( $header, 255, 14 ), "\0 " );
        $folder = substr( $header, 281, 4096 );
        $nul = strpos( $folder, "\0" );
        $folder = $nul === false ? substr( $folder, 0, -8 ) : substr( $folder, 0, $nul );
        $folder = trim( $folder );
        if ( ! ctype_digit( $digits ) ) {
            throw new RuntimeException( 'Invalid archive entry size.' );
        }
        $length = (int) $digits;
        if ( $length > $size - $header_bytes - $cursor - $header_bytes ) {
            throw new RuntimeException( 'Archive entry exceeds file.' );
        }
        $sql = $name === 'database.sql' && ( $folder === '' || $folder === '.' );
        if ( $sql ) {
            $injected++;
            $new_length = $length + strlen( $extra );
            if ( $new_length > 99999999999999 ) {
                throw new RuntimeException( 'SQL fixture size overflow.' );
            }
            $header = substr_replace( $header, pack( 'a14', (string) $new_length ), 255, 14 );
        }
        tr_fixture_write( $output, $header );
        $left = $length;
        while ( $left > 0 ) {
            $chunk = fread( $input, min( $left, 1048576 ) );
            if ( ! is_string( $chunk ) || $chunk === '' ) {
                throw new RuntimeException( 'Truncated fixture data.' );
            }
            tr_fixture_write( $output, $chunk );
            $left -= strlen( $chunk );
        }
        if ( $sql ) {
            tr_fixture_write( $output, $extra );
        }
        $cursor += $header_bytes + $length;
    }
    if ( $cursor !== $size - $header_bytes || $injected !== 1 ) {
        throw new RuntimeException( 'Expected one root database.sql entry.' );
    }
    tr_fixture_write( $output, str_repeat( "\0", $header_bytes ) );
    if ( ! fflush( $output ) ) {
        throw new RuntimeException( 'Cannot flush SQL fixture.' );
    }
} catch ( Throwable $e ) {
    fclose( $input );
    fclose( $output );
    @unlink( $target );
    fwrite( STDERR, "SQL fixture creation failed: " . $e->getMessage() . "\n" );
    exit( 1 );
}
fclose( $input );
fclose( $output );
echo $mode === 'sleep' ? "Valid archive with a post-DDL SQL pause created.\n" : "Valid archive with a post-DDL SQL execution error created.\n";
