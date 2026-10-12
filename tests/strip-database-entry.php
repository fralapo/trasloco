<?php
/**
 * CI-only negative fixture: remove database.sql from a genuine .wpress.
 * Preserve all other blocks and the standard EOF marker without buffering
 * large archive contents into memory. No WordPress dependency.
 */
if ( PHP_SAPI !== 'cli' || $argc !== 3 ) {
    fwrite( STDERR, "Usage: php tests/strip-database-entry.php INPUT.wpress OUTPUT.wpress\n" );
    exit( 2 );
}
$input = $argv[1];
$output = $argv[2];
if ( $input === $output || ! is_file( $input ) || ! is_readable( $input ) ||
    is_link( $input ) || file_exists( $output ) || is_link( $output ) ) {
    fwrite( STDERR, "Fixture input or destination is unsafe.\n" );
    exit( 1 );
}
$in = fopen( $input, 'rb' );
$out = fopen( $output, 'xb' );
if ( ! $in || ! $out ) {
    fwrite( STDERR, "Cannot open fixture streams.\n" );
    exit( 1 );
}
$header_bytes = 4377;
$length = filesize( $input );
$offset = 0;
$removed = 0;
try {
    while ( $offset < $length - $header_bytes ) {
        if ( fseek( $in, $offset ) !== 0 ) {
            throw new RuntimeException( 'Cannot seek archive fixture.' );
        }
        $header = fread( $in, $header_bytes );
        if ( strlen( $header ) !== $header_bytes ) {
            throw new RuntimeException( 'Incomplete archive fixture header.' );
        }
        $filename = trim( substr( $header, 0, 255 ), "\0 " );
        $text = trim( substr( $header, 255, 14 ), "\0 " );
        $folder = substr( $header, 281, 4096 );
        $nul = strpos( $folder, "\0" );
        $folder = $nul !== false ? substr( $folder, 0, $nul ) : substr( $folder, 0, -8 );
        $folder = trim( $folder );
        if ( ! ctype_digit( $text ) ) {
            throw new RuntimeException( 'Invalid archive block size.' );
        }
        $size = (int) $text;
        if ( $size < 0 || $size > $length - $header_bytes - $offset - $header_bytes ) {
            throw new RuntimeException( 'Archive block length exceeds file.' );
        }
        if ( $filename === 'database.sql' && ( $folder === '' || $folder === '.' ) ) {
            $removed++;
        } else {
            if ( fwrite( $out, $header ) !== $header_bytes ) {
                throw new RuntimeException( 'Cannot write fixture header.' );
            }
            $left = $size;
            while ( $left > 0 ) {
                $chunk = fread( $in, min( $left, 1048576 ) );
                if ( ! is_string( $chunk ) || $chunk === '' ) {
                    throw new RuntimeException( 'Cannot read fixture data.' );
                }
                $at = 0;
                while ( $at < strlen( $chunk ) ) {
                    $n = fwrite( $out, substr( $chunk, $at ) );
                    if ( $n === false || $n === 0 ) {
                        throw new RuntimeException( 'Cannot write fixture data.' );
                    }
                    $at += $n;
                }
                $left -= strlen( $chunk );
            }
        }
        $offset += $header_bytes + $size;
    }
    if ( $offset !== $length - $header_bytes || $removed !== 1 ) {
        throw new RuntimeException( 'Expected exactly one root database.sql.' );
    }
    if ( fwrite( $out, str_repeat( "\0", $header_bytes ) ) !== $header_bytes ) {
        throw new RuntimeException( 'Cannot write archive trailer.' );
    }
} catch ( Throwable $e ) {
    fclose( $in );
    fclose( $out );
    @unlink( $output );
    fwrite( STDERR, "Invalid negative fixture: " . $e->getMessage() . "\n" );
    exit( 1 );
}
fclose( $in );
fclose( $out );
echo "Created a real .wpress archive with database.sql deliberately missing.\n";
