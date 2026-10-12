<?php
/**
 * Read-only .wpress inventory for planning a restore.
 * This is NOT a database diff and never modifies target files or tables.
 */
class Trasloco_Archive_Inspector {
    public static function inspect( $archive, $sample_limit = 30 ) {
        if ( ! is_file( $archive ) || ! is_readable( $archive ) || is_link( $archive ) ) {
            throw new Trasloco_Archive_Exception( 'Archive cannot be inspected.' );
        }
        $extractor = new Trasloco_Extractor( $archive );
        $valid = $extractor->is_valid();
        $extractor->close();
        if ( ! $valid ) {
            throw new Trasloco_Archive_Exception( 'Archive structure is invalid.' );
        }
        $handle = fopen( $archive, 'rb' );
        $length = filesize( $archive );
        $position = 0;
        $total = 0;
        $bytes = 0;
        $existing = 0;
        $not_present = 0;
        $groups = array();
        $sample = array();
        // Duplicate root metadata makes archive interpretation ambiguous.
        // These entries define how the database and site are restored.
        $reserved_root = array(
            'package.json' => true, 'database.sql' => true,
            'multisite.json' => true, 'blogs.json' => true,
        );
        $seen_root = array();
        try {
            while ( $position < $length - 4377 ) {
                if ( fseek( $handle, $position, SEEK_SET ) !== 0 ) {
                    throw new Trasloco_Archive_Exception( 'Cannot read archive inventory.' );
                }
                $header = fread( $handle, 4377 );
                if ( ! is_string( $header ) || strlen( $header ) !== 4377 ) {
                    throw new Trasloco_Archive_Exception( 'Invalid archive entry.' );
                }
                $filename = trim( substr( $header, 0, 255 ), "\0 " );
                $size_str = trim( substr( $header, 255, 14 ), "\0 " );
                $folder = substr( $header, 281, 4096 );
                if ( ( $nul = strpos( $folder, "\0" ) ) !== false ) {
                    $folder = substr( $folder, 0, $nul );
                } elseif ( strlen( $folder ) === 4096 ) {
                    $folder = substr( $folder, 0, -8 );
                }
                $folder = trim( str_replace( '\\', '/', $folder ) );
                if ( $folder === '.' ) { $folder = ''; }
                $invalid_folder = false;
                if ( $folder !== '' ) {
                    foreach ( explode( '/', $folder ) as $component ) {
                        if ( $component === '' || $component === '.' || $component === '..' ) {
                            $invalid_folder = true;
                            break;
                        }
                    }
                }
                if ( ! ctype_digit( $size_str ) || $filename === '' ||
                    $filename === '.' || $filename === '..' ||
                    strpbrk( $filename, '/\\' ) !== false ||
                    preg_match( '/[\\x00-\\x1f\\x7f]/', $filename . $folder ) ||
                    preg_match( '#^([/\\\\]|[A-Za-z]:)#', $folder ) || $invalid_folder ) {
                    throw new Trasloco_Archive_Exception( 'Unsafe entry in archive.' );
                }
                $size = (int) $size_str;
                if ( $size < 0 || $position + 4377 + $size > $length - 4377 ) {
                    throw new Trasloco_Archive_Exception( 'Invalid archive entry length.' );
                }
                if ( $folder === '' && isset( $reserved_root[ $filename ] ) ) {
                    if ( isset( $seen_root[ $filename ] ) ) {
                        throw new Trasloco_Archive_Exception( 'Duplicate root metadata in archive.' );
                    }
                    $seen_root[ $filename ] = true;
                }
                $relative = ltrim( ( $folder === '' ? '' : $folder . '/' ) . $filename, '/' );
                $group = $folder === '' ? 'root' : explode( '/', $folder )[0];
                if ( ! isset( $groups[ $group ] ) ) {
                    $groups[ $group ] = 0;
                }
                $groups[ $group ]++;
                // package and SQL metadata are not destination wp-content files.
                if ( $group !== 'root' ) {
                    if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) ) ) {
                        $existing++;
                    } else {
                        $not_present++;
                    }
                }
                if ( count( $sample ) < max( 0, min( 100, (int) $sample_limit ) ) ) {
                    $sample[] = array( 'relative_path' => $relative, 'size_bytes' => $size );
                }
                $total++;
                if ( $total > 1000000 ) {
                    throw new Trasloco_Archive_Exception( 'Archive file inventory exceeds the safe entry limit.' );
                }
                $bytes += $size;
                $position += 4377 + $size;
            }
        } finally {
            fclose( $handle );
        }
        ksort( $groups, SORT_STRING );
        return array(
            'archive_bytes' => $length,
            'files_in_archive' => $total,
            'uncompressed_bytes' => $bytes,
            'present_on_destination' => $existing,
            'not_present_on_destination' => $not_present,
            'groups' => $groups,
            'sample' => $sample,
            'warning' => 'Read-only file inventory. It does not compare file contents, database rows or plugin compatibility.',
        );
    }
}
