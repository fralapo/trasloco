<?php
/**
 * Standalone filesystem recovery receipts.
 *
 * The imported SQL may replace WordPress options, including any previous
 * recovery metadata. Store an independent receipt beside the private archive,
 * before the import writes destination files or tables.
 *
 * A pending receipt means "inspect/recover manually", not "auto-rollback".
 * Uses no WordPress functions so it can be read after WordPress/MySQL fails.
 */
class Trasloco_Recovery_Journal {
    const PREFIX = '.trasloco-recovery-';
    const VERSION = 1;

    private static function directory( $directory ) {
        if ( ! is_string( $directory ) || is_link( $directory ) ||
             ! is_dir( $directory ) || ! is_readable( $directory ) ) {
            throw new RuntimeException( 'Private recovery directory is unavailable.' );
        }
        $real = realpath( $directory );
        if ( $real === false ) {
            throw new RuntimeException( 'Private recovery directory is unavailable.' );
        }
        return $real;
    }

    private static function archive_name( $name ) {
        if ( ! is_string( $name ) || strlen( $name ) > 220 ||
             strpos( $name, '..' ) !== false ||
             ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) ) {
            throw new InvalidArgumentException( 'Invalid recovery archive filename.' );
        }
        return $name;
    }

    private static function receipt_path( $directory, $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
            throw new InvalidArgumentException( 'Invalid recovery receipt identifier.' );
        }
        return self::directory( $directory ) . DIRECTORY_SEPARATOR . self::PREFIX . $id . '.json';
    }

    private static function publish( $path, $data, $exclusive ) {
        $encoded = json_encode( $data, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > 2048 ||
             ( $exclusive && ( file_exists( $path ) || is_link( $path ) ) ) ) {
            throw new RuntimeException( 'Recovery receipt cannot be published.' );
        }
        $parent = dirname( $path );
        if ( ! is_writable( $parent ) ) {
            throw new RuntimeException( 'Recovery directory is not writable.' );
        }
        $temp = tempnam( $parent, '.tr-receipt-' );
        if ( $temp === false ) {
            throw new RuntimeException( 'Cannot allocate recovery receipt.' );
        }
        try {
            @chmod( $temp, 0600 );
            if ( file_put_contents( $temp, $encoded, LOCK_EX ) !== strlen( $encoded ) ||
                 ( $exclusive && ( file_exists( $path ) || is_link( $path ) ) ) ||
                 ! rename( $temp, $path ) ) {
                throw new RuntimeException( 'Cannot save recovery receipt.' );
            }
        } catch ( Throwable $e ) {
            @unlink( $temp );
            throw $e;
        }
    }

    public static function begin( $directory, $source_archive, $recovery_archive ) {
        $dir = self::directory( $directory );
        $source = self::archive_name( $source_archive );
        $recovery = self::archive_name( $recovery_archive );
        $archive = $dir . DIRECTORY_SEPARATOR . $recovery;
        if ( ! is_file( $archive ) || ! is_readable( $archive ) ||
             is_link( $archive ) || ! is_file( $archive . '.manifest.json' ) ) {
            throw new RuntimeException( 'A verified recovery archive is required.' );
        }
        $id = bin2hex( random_bytes( 16 ) );
        self::publish( self::receipt_path( $dir, $id ), array(
            'version' => self::VERSION,
            'id' => $id,
            'status' => 'pending',
            'source_archive' => $source,
            'recovery_archive' => $recovery,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ), true );
        return $id;
    }

    public static function complete( $directory, $id ) {
        $path = self::receipt_path( $directory, $id );
        if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 2048 ) {
            throw new RuntimeException( 'Recovery receipt is unavailable.' );
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $data ) || ! isset( $data['id'], $data['status'] ) ||
             $data['id'] !== $id || $data['status'] !== 'pending' ) {
            throw new RuntimeException( 'Recovery receipt cannot be completed.' );
        }
        $data['status'] = 'completed';
        $data['completed_at'] = gmdate( 'Y-m-d H:i:s' );
        self::publish( $path, $data, false );
    }

    public static function entries( $directory, $limit = 100 ) {
        $dir = self::directory( $directory );
        $limit = max( 1, min( 100, (int) $limit ) );
        $entries = array();
        foreach ( new DirectoryIterator( $dir ) as $item ) {
            if ( count( $entries ) >= $limit ) {
                break;
            }
            if ( $item->isDot() || $item->isLink() || ! $item->isFile() ||
                 ! preg_match( '/^\.trasloco-recovery-([a-f0-9]{32})\.json$/D', $item->getFilename(), $matches ) ||
                 $item->getSize() > 2048 ) {
                continue;
            }
            $data = json_decode( (string) file_get_contents( $item->getPathname() ), true );
            if ( ! is_array( $data ) || ! isset( $data['id'], $data['status'],
                      $data['source_archive'], $data['recovery_archive'] ) ||
                 $data['id'] !== $matches[1] ||
                 ! in_array( $data['status'], array( 'pending', 'completed' ), true ) ) {
                continue;
            }
            try {
                self::archive_name( $data['source_archive'] );
                self::archive_name( $data['recovery_archive'] );
            } catch ( InvalidArgumentException $e ) {
                continue;
            }
            $entries[] = $data;
        }
        usort( $entries, function ( $a, $b ) {
            return strcmp( $b['created_at'], $a['created_at'] );
        } );
        return $entries;
    }
}
