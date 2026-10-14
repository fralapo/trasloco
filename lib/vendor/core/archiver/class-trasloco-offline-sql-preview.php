<?php
/**
 * Bounded, read-only summary of table operations in a verified .wpress SQL dump.
 * This is an operation inventory, not a semantic database diff or SQL validator.
 */
class Trasloco_Offline_SQL_Preview {
    const MAX_SQL_BYTES = 1073741824;
    const MAX_TABLES = 10000;
    const READ_CHUNK = 65536;

    public static function preview( $archive, $max_bytes = self::MAX_SQL_BYTES ) {
        if ( ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > self::MAX_SQL_BYTES ) {
            throw new InvalidArgumentException( 'A SQL preview byte limit between 1 and 1 GiB is required.' );
        }
        require_once __DIR__ . '/class-trasloco-offline-recovery.php';
        $inspection = Trasloco_Offline_Recovery::inspect( $archive );
        if ( $inspection['integrity'] !== 'sha256_verified' ) {
            throw new RuntimeException( 'A SHA-256-verified recovery archive is required.' );
        }
        if ( ! isset( $inspection['files']['database.sql'] ) ) {
            return array(
                'integrity' => 'sha256_verified',
                'database_sql' => 'not_present',
                'tables' => array(),
                'tables_seen' => 0,
                'sql_bytes_scanned' => 0,
                'warning' => 'No SQL is present. This inventory does not execute SQL or compare database rows.',
            );
        }

        $sql = $inspection['files']['database.sql'];
        if ( $sql['size'] > $max_bytes ) {
            throw new RuntimeException( 'SQL preview exceeds the configured byte limit.' );
        }
        clearstatcache( true, $archive );
        $before = @lstat( $archive );
        $handle = @fopen( $archive, 'rb' );
        if ( ! is_array( $before ) || ! $handle || fseek( $handle, $sql['offset'], SEEK_SET ) !== 0 ) {
            if ( $handle ) { fclose( $handle ); }
            throw new RuntimeException( 'Cannot open SQL from the recovery archive.' );
        }

        $operations = array();
        $scanned = 0;
        try {
            while ( $scanned < $sql['size'] ) {
                $line = fgets( $handle, min( self::READ_CHUNK, $sql['size'] - $scanned + 1 ) );
                if ( ! is_string( $line ) || $line === '' ) {
                    throw new RuntimeException( 'SQL entry ended before its declared length.' );
                }
                $scanned += strlen( $line );
                if ( $scanned > $sql['size'] ) {
                    throw new RuntimeException( 'SQL entry exceeded its declared length.' );
                }
                if ( preg_match( '/^\s*(DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE\s+TABLE)\s+`([^`]{1,191})`/i', $line, $match ) ) {
                    $table = $match[2];
                    if ( preg_match( '/[\x00-\x1f\x7f]/', $table ) ) {
                        continue;
                    }
                    if ( ! isset( $operations[ $table ] ) ) {
                        if ( count( $operations ) >= self::MAX_TABLES ) {
                            throw new RuntimeException( 'SQL preview exceeds the table limit.' );
                        }
                        $operations[ $table ] = array(
                            'drop' => 0, 'create' => 0, 'insert' => 0,
                            'replace' => 0, 'alter' => 0, 'truncate' => 0,
                        );
                    }
                    $kind = strtoupper( preg_replace( '/\s+/', ' ', $match[1] ) );
                    $key = array(
                        'DROP TABLE IF EXISTS' => 'drop', 'CREATE TABLE' => 'create',
                        'INSERT INTO' => 'insert', 'REPLACE INTO' => 'replace',
                        'ALTER TABLE' => 'alter', 'TRUNCATE TABLE' => 'truncate',
                    )[ $kind ];
                    $operations[ $table ][ $key ]++;
                }
            }
        } finally {
            fclose( $handle );
        }

        clearstatcache( true, $archive );
        $after = @lstat( $archive );
        if ( ! is_array( $after ) || $before['size'] !== $after['size'] ||
            $before['mtime'] !== $after['mtime'] || $before['ctime'] !== $after['ctime'] ||
            $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev'] ) {
            throw new RuntimeException( 'Recovery archive changed during SQL preview.' );
        }
        ksort( $operations, SORT_STRING );
        return array(
            'integrity' => 'sha256_verified',
            'database_sql' => 'present',
            'tables' => $operations,
            'tables_seen' => count( $operations ),
            'sql_bytes_scanned' => $scanned,
            'warning' => 'Read-only statement inventory from Trasloco-generated SQL. It does not execute SQL, validate arbitrary SQL syntax, count affected rows, compare row values, or inspect the destination database. NOT a database diff or restore approval.',
        );
    }
}
