<?php
/**
 * Restricted, read-only .wpress scanner for extracting ONE regular upload.
 * Restores a media file without touching the database or existing files.
 *
 * Requires SHA-256 verified .wpress; refuses symlink traversal or PHP content.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Selective {
    const HEADER_BYTES = 4377;
    const MAX_FILE_BYTES = 1073741824; // 1 GiB safety ceiling.

    private static function input_name( $relative ) {
        if ( ! is_string( $relative ) || $relative === '' || strlen( $relative ) > 512 ||
            $relative[0] === '/' || strpos( $relative, chr(92) ) !== false ||
            ! preg_match( '#^[A-Za-z0-9_.\\/-]+$#D', $relative ) ) {
            throw new RuntimeException( 'Invalid relative media path.' );
        }
        foreach ( explode( '/', $relative ) as $component ) {
            if ( $component === '' || $component === '.' || $component === '..' ) {
                throw new RuntimeException( 'Unsafe media path component.' );
            }
        }
        $extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf', 'txt', 'mp4', 'mov', 'webm', 'mp3', 'wav' ), true ) ) {
            throw new RuntimeException( 'This restore operation only supports approved non-executable media.' );
        }
        return $relative;
    }

    private static function target( $relative ) {
        $root = realpath( WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' );
        if ( $root === false || ! is_dir( $root ) || is_link( $root ) ) {
            throw new RuntimeException( 'Upload directory is not a safe real directory.' );
        }
        $parts = explode( '/', $relative );
        $file = array_pop( $parts );
        $dir = $root;
        foreach ( $parts as $part ) {
            $dir .= DIRECTORY_SEPARATOR . $part;
            if ( is_link( $dir ) ) {
                throw new RuntimeException( 'Symlink encountered in destination path.' );
            }
            if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755 ) ) {
                throw new RuntimeException( 'Could not create the media destination folder.' );
            }
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $file;
        if ( file_exists( $dest ) || is_link( $dest ) ) {
            throw new RuntimeException( 'Destination file already exists. No files were overwritten.' );
        }
        return $dest;
    }

    public static function restore_media( $archive_name, $relative ) {
        $relative = self::input_name( $relative );
        $verified = Trasloco_Manifest::inspect( $archive_name );
        if ( $verified['status'] !== 'verified' ) {
            throw new RuntimeException( 'A SHA-256 verified backup is required for selective recovery.' );
        }
        $archive_path = Trasloco_Manifest::archive_path( $archive_name );
        $file_bytes = filesize( $archive_path );
        $source = fopen( $archive_path, 'rb' );
        if ( $source === false ) {
            throw new RuntimeException( 'Could not open backup archive.' );
        }
        $temp = null;
        try {
            $cursor = 0;
            while ( $cursor <= $file_bytes - self::HEADER_BYTES ) {
                if ( fseek( $source, $cursor, SEEK_SET ) !== 0 ) {
                    throw new RuntimeException( 'Cannot seek archive header.' );
                }
                $block = fread( $source, self::HEADER_BYTES );
                if ( ! is_string( $block ) || strlen( $block ) !== self::HEADER_BYTES ) {
                    throw new RuntimeException( 'Invalid archive header.' );
                }
                $data = unpack( 'a255name/a14length/a12mtime/a4096directory', $block );
                $name = trim( $data['name'], chr(0) . ' ' );
                if ( $name === '' ) {
                    break;
                }
                $size_text = trim( $data['length'], chr(0) . ' ' );
                if ( ! ctype_digit( $size_text ) ) {
                    throw new RuntimeException( 'Invalid archive entry size.' );
                }
                $size = (int) $size_text;
                if ( $size < 0 || $size > $file_bytes - $cursor - self::HEADER_BYTES ) {
                    throw new RuntimeException( 'Archive entry exceeds file bounds.' );
                }
                $directory = $data['directory'];
                if ( ( $zero = strpos( $directory, chr(0) ) ) !== false ) {
                    $directory = substr( $directory, 0, $zero );
                } elseif ( strlen( $directory ) === 4096 ) {
                    $directory = substr( $directory, 0, -8 );
                }
                $directory = trim( $directory );
                $entry = ( $directory === '.' || $directory === '' ) ? $name : $directory . '/' . $name;
                $entry = str_replace( chr(92), '/', $entry );
                if ( $entry === 'uploads/' . $relative ) {
                    if ( $size > self::MAX_FILE_BYTES ) {
                        throw new RuntimeException( 'Selected media exceeds 1 GiB safety limit.' );
                    }
                    // Resolve only after a matching entry is confirmed.
                    $destination = self::target( $relative );
                    $temp = $destination . '.trasloco-partial-' . bin2hex( random_bytes( 6 ) );
                    $output = @fopen( $temp, 'xb' );
                    if ( $output === false ) {
                        throw new RuntimeException( 'Could not create media temporary file.' );
                    }
                    try {
                        $remaining = $size;
                        while ( $remaining > 0 ) {
                            $chunk = fread( $source, min( 1048576, $remaining ) );
                            if ( $chunk === false || $chunk === '' ) {
                                throw new RuntimeException( 'Media payload is incomplete.' );
                            }
                            $written = fwrite( $output, $chunk );
                            if ( $written !== strlen( $chunk ) ) {
                                throw new RuntimeException( 'Media restore wrote an incomplete chunk.' );
                            }
                            $remaining -= strlen( $chunk );
                        }
                        if ( ! fflush( $output ) ) {
                            throw new RuntimeException( 'Unable to flush recovered media.' );
                        }
                    } finally {
                        fclose( $output );
                    }
                    if ( is_link( $destination ) || file_exists( $destination ) || ! @rename( $temp, $destination ) ) {
                        throw new RuntimeException( 'Failed to finalize recovered media file.' );
                    }
                    return $destination;
                }
                $cursor += self::HEADER_BYTES + $size;
            }
            throw new RuntimeException( 'Requested media is not present in the backup.' );
        } finally {
            fclose( $source );
            if ( $temp !== null && is_file( $temp ) ) {
                @unlink( $temp );
            }
        }
    }
}
