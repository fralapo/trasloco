<?php
/**
 * Read-only, bounded row comparison for Trasloco-generated SQL dumps.
 *
 * The report compares raw archive values with current destination values. It
 * never executes SQL or predicts importer URL/serialization transformations.
 */
class Trasloco_SQL_Changeset_Preview {
    const MAX_SQL_BYTES = 1073741824;
    const MAX_ROW_BYTES = 8388608;
    const MAX_ROWS_PER_TABLE = 5000;
    const MAX_TOTAL_ROWS = 10000;
    const MAX_TOTAL_BYTES = 33554432;
    const MAX_TABLES = 50;

    /** Compare selected source tables with same-named destination suffixes. */
    public static function compare( $archive, $suffixes ) {
        global $wpdb;
        if ( ! is_array( $suffixes ) || empty( $suffixes ) || count( $suffixes ) > self::MAX_TABLES ) {
            throw new InvalidArgumentException( 'Select between 1 and 50 tables for a row comparison.' );
        }
        if ( is_multisite() ) {
            throw new RuntimeException( 'Row comparison is available only on single-site WordPress.' );
        }
        $suffixes = array_values( array_unique( $suffixes ) );
        foreach ( $suffixes as $suffix ) {
            if ( ! is_string( $suffix ) || ! preg_match( '/^[A-Za-z0-9_$]{1,180}$/D', $suffix ) ||
                strpos( $suffix, 'basesite_' ) === 0 || preg_match( '/^[0-9]+_/', $suffix ) ) {
                throw new InvalidArgumentException( 'A selected table suffix is not safely mappable.' );
            }
        }

        require_once __DIR__ . '/class-trasloco-offline-recovery.php';
        require_once __DIR__ . '/class-trasloco-integrity.php';
        if ( Trasloco_Integrity::verify( $archive )['status'] !== 'verified' ) {
            throw new RuntimeException( 'A SHA-256-verified archive is required.' );
        }
        $inspection = Trasloco_Offline_Recovery::inspect( $archive );
        if ( $inspection['integrity'] !== 'sha256_verified' || ! isset( $inspection['files']['database.sql'] ) ) {
            throw new RuntimeException( 'A verified archive containing database.sql is required.' );
        }
        if ( $inspection['files']['database.sql']['size'] > self::MAX_SQL_BYTES ) {
            throw new RuntimeException( 'SQL changeset preview exceeds its 1 GiB scan limit.' );
        }

        $selected = array();
        foreach ( $suffixes as $suffix ) {
            $source = 'SERVMASK_PREFIX_' . $suffix;
            $target = $wpdb->prefix . $suffix;
            if ( strlen( $target ) > 64 ) {
                throw new RuntimeException( 'A destination table name exceeds the MySQL identifier limit.' );
            }
            $selected[ $source ] = array( 'suffix' => $suffix, 'destination' => $target );
        }
        $source_tables = self::read_source_tables( $archive, $inspection['files']['database.sql'], $selected );

        $total_rows = 0;
        $total_bytes = 0;
        $results = array();
        foreach ( $selected as $source => $target_info ) {
            if ( ! isset( $source_tables[ $source ] ) ) {
                throw new RuntimeException( 'A selected source table is missing from the archive: ' . $target_info['suffix'] );
            }
            $source_table = $source_tables[ $source ];
            $target = $target_info['destination'];
            $columns = self::destination_columns( $target );
            if ( $columns === null ) {
                $results[] = self::unavailable( $source, $target, 'destination_table_missing' );
                continue;
            }
            if ( $columns !== $source_table['columns'] ) {
                $results[] = self::unavailable( $source, $target, 'schema_mismatch' );
                continue;
            }
            $primary = self::destination_primary_key( $target );
            if ( empty( $source_table['primary_key'] ) || $primary !== $source_table['primary_key'] ) {
                $results[] = self::unavailable( $source, $target, 'primary_key_mismatch_or_missing' );
                continue;
            }
            if ( count( $source_table['rows'] ) > self::MAX_ROWS_PER_TABLE ) {
                $results[] = self::unavailable( $source, $target, 'source_row_limit_exceeded' );
                continue;
            }
            $total_rows += count( $source_table['rows'] );
            if ( $total_rows > self::MAX_TOTAL_ROWS ) {
                throw new RuntimeException( 'SQL changeset preview exceeds its total row limit.' );
            }
            foreach ( $source_table['rows'] as $row ) {
                $total_bytes += self::row_bytes( $row );
            }
            if ( $total_bytes > self::MAX_TOTAL_BYTES ) {
                throw new RuntimeException( 'SQL changeset preview exceeds its total data limit.' );
            }
            $destination_map = self::destination_rows( $target, $columns, $primary );
            if ( $destination_map === null ) {
                $results[] = self::unavailable( $source, $target, 'destination_row_limit_exceeded' );
                continue;
            }
            $total_rows += count( $destination_map );
            if ( $total_rows > self::MAX_TOTAL_ROWS ) {
                throw new RuntimeException( 'SQL changeset preview exceeds its total row limit.' );
            }
            $results[] = self::compare_rows( $source, $target, $source_table, $destination_map, $primary );
        }
        return array(
            'status' => 'available',
            'comparison_basis' => 'raw_archive_values',
            'tables' => $results,
            'rows_scanned' => $total_rows,
            'bytes_scanned' => $total_bytes,
            'warning' => 'Read-only row comparison of raw archive values and current destination values. Import may transform URLs, serialized values and table prefixes; this preview does not execute SQL, validate arbitrary SQL or predict those transformations. Stop site writes while reviewing. It is advisory, not restore approval.',
        );
    }

    /** Parse only selected tables and only the SQL emitted by Trasloco's row-wise exporter. */
    private static function read_source_tables( $archive, $sql, $selected ) {
        clearstatcache( true, $archive );
        $before = @lstat( $archive );
        $handle = @fopen( $archive, 'rb' );
        if ( ! is_array( $before ) || ! $handle || fseek( $handle, $sql['offset'], SEEK_SET ) !== 0 ) {
            if ( $handle ) { fclose( $handle ); }
            throw new RuntimeException( 'Cannot open the archive SQL entry.' );
        }
        $tables = array();
        $scanned = 0;
        $source_rows = 0;
        $source_bytes = 0;
        $create_table = null;
        $create_sql = '';
        try {
            while ( $scanned < $sql['size'] ) {
                $line = fgets( $handle, min( self::MAX_ROW_BYTES + 2, $sql['size'] - $scanned + 1 ) );
                if ( ! is_string( $line ) || $line === '' ) {
                    throw new RuntimeException( 'SQL entry ended before its declared length.' );
                }
                $scanned += strlen( $line );
                if ( $scanned > $sql['size'] ) {
                    throw new RuntimeException( 'SQL entry exceeded its declared length.' );
                }
                if ( strlen( $line ) > self::MAX_ROW_BYTES && strpos( $line, 'INSERT INTO ' ) !== false ) {
                    throw new RuntimeException( 'A selected SQL row exceeds the 8 MiB per-row parsing limit.' );
                }
                if ( $create_table !== null ) {
                    $create_sql .= $line;
                    if ( strpos( $line, ';' ) !== false ) {
                        $tables[ $create_table ]['columns'] = self::create_columns( $create_sql );
                        $tables[ $create_table ]['primary_key'] = self::create_primary_key( $create_sql );
                        $create_table = null;
                        $create_sql = '';
                    }
                    continue;
                }
                if ( preg_match( '/^\s*CREATE\s+TABLE\s+`([^`]+)`/i', $line, $match ) && isset( $selected[ $match[1] ] ) ) {
                    $create_table = $match[1];
                    $create_sql = $line;
                    if ( strpos( $line, ';' ) !== false ) {
                        $tables[ $create_table ]['columns'] = self::create_columns( $create_sql );
                        $tables[ $create_table ]['primary_key'] = self::create_primary_key( $create_sql );
                        $create_table = null;
                        $create_sql = '';
                    }
                    continue;
                }
                if ( preg_match( '/^\s*INSERT\s+INTO\s+`([^`]+)`\s+VALUES\s+\((.*)\);\s*$/isD', $line, $match ) && isset( $selected[ $match[1] ] ) ) {
                    $table = $match[1];
                    if ( ! isset( $tables[ $table ]['rows'] ) ) {
                        $tables[ $table ]['rows'] = array();
                    }
                    if ( count( $tables[ $table ]['rows'] ) >= self::MAX_ROWS_PER_TABLE + 1 ) {
                        continue;
                    }
                    $row = self::parse_row( $match[2] );
                    $source_rows++;
                    $source_bytes += self::row_bytes( $row );
                    if ( $source_rows > self::MAX_TOTAL_ROWS || $source_bytes > self::MAX_TOTAL_BYTES ) {
                        throw new RuntimeException( 'SQL changeset preview exceeds its row or data limit.' );
                    }
                    $tables[ $table ]['rows'][] = $row;
                }
            }
        } finally {
            fclose( $handle );
        }
        if ( $create_table !== null ) {
            throw new RuntimeException( 'A selected CREATE TABLE statement is incomplete.' );
        }
        clearstatcache( true, $archive );
        $after = @lstat( $archive );
        if ( ! is_array( $after ) || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] ||
            $before['ctime'] !== $after['ctime'] || $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev'] ) {
            throw new RuntimeException( 'Archive changed during SQL changeset preview.' );
        }
        return $tables;
    }

    private static function create_columns( $sql ) {
        if ( ! preg_match( '/CREATE\s+TABLE\s+`[^`]+`\s*\((.*)\)\s*(?:ENGINE|;)/is', $sql, $body ) ) {
            throw new RuntimeException( 'A selected CREATE TABLE statement has an unsupported shape.' );
        }
        $columns = array();
        foreach ( preg_split( '/\r?\n/', $body[1] ) as $line ) {
            if ( preg_match( '/^\s*`([^`]{1,64})`\s+/', $line, $column ) ) {
                $columns[] = $column[1];
            }
        }
        if ( empty( $columns ) || count( $columns ) > 512 || count( $columns ) !== count( array_unique( $columns ) ) ) {
            throw new RuntimeException( 'A selected table has unsupported column metadata.' );
        }
        return $columns;
    }

    private static function create_primary_key( $sql ) {
        if ( ! preg_match( '/PRIMARY\s+KEY\s*\(([^)]{1,1024})\)/i', $sql, $match ) ) {
            return array();
        }
        preg_match_all( '/`([^`]{1,64})`/', $match[1], $keys );
        return isset( $keys[1] ) ? $keys[1] : array();
    }

    private static function parse_row( $tuple ) {
        $values = array();
        $field = '';
        $quoted = false;
        $escaped = false;
        $length = strlen( $tuple );
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $tuple[ $i ];
            if ( $escaped ) {
                $field .= $char;
                $escaped = false;
            } elseif ( $quoted && $char === '\\' ) {
                $field .= $char;
                $escaped = true;
            } elseif ( $char === "'" ) {
                $quoted = ! $quoted;
                $field .= $char;
            } elseif ( $char === ',' && ! $quoted ) {
                $values[] = self::parse_value( trim( $field ) );
                $field = '';
            } else {
                $field .= $char;
            }
        }
        if ( $quoted || $escaped ) {
            throw new RuntimeException( 'A selected INSERT row has an unterminated string value.' );
        }
        $values[] = self::parse_value( trim( $field ) );
        return $values;
    }

    private static function parse_value( $value ) {
        if ( strcasecmp( $value, 'NULL' ) === 0 ) {
            return null;
        }
        if ( strlen( $value ) >= 2 && $value[0] === "'" && substr( $value, -1 ) === "'" ) {
            $raw = substr( $value, 1, -1 );
            require_once dirname( __DIR__ ) . '/database/class-trasloco-database-utility.php';
            return Trasloco_Database_Utility::unescape_mysql( $raw );
        }
        if ( preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/D', $value ) ) {
            return $value;
        }
        throw new RuntimeException( 'A selected INSERT row contains an unsupported SQL value.' );
    }

    private static function destination_columns( $table ) {
        global $wpdb;
        $quoted = self::quote_identifier( $table );
        $rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $quoted, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            throw new RuntimeException( 'Could not read destination table columns.' );
        }
        if ( empty( $rows ) ) {
            return null;
        }
        return array_map( function ( $row ) { return $row['Field']; }, $rows );
    }

    private static function destination_primary_key( $table ) {
        global $wpdb;
        $rows = $wpdb->get_results( 'SHOW KEYS FROM ' . self::quote_identifier( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
        if ( ! is_array( $rows ) ) {
            throw new RuntimeException( 'Could not read destination primary-key metadata.' );
        }
        usort( $rows, function ( $left, $right ) { return (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index']; } );
        return array_map( function ( $row ) { return $row['Column_name']; }, $rows );
    }

    private static function destination_rows( $table, $columns, $primary ) {
        global $wpdb;
        $row_parts = array_map( array( __CLASS__, 'sql_value_digest_part' ), $columns );
        $key_parts = array_map( array( __CLASS__, 'sql_value_digest_part' ), $primary );
        $row_digest = 'SHA2(CONCAT(' . implode( ', ', $row_parts ) . '), 256)';
        $key_digest = 'SHA2(CONCAT(' . implode( ', ', $key_parts ) . '), 256)';
        $query = 'SELECT ' . $key_digest . ' AS key_hash, ' . $row_digest . ' AS row_hash FROM ' .
            self::quote_identifier( $table ) . ' LIMIT ' . ( self::MAX_ROWS_PER_TABLE + 1 );
        $rows = $wpdb->get_results( $query, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            throw new RuntimeException( 'Could not read destination rows for comparison.' );
        }
        if ( count( $rows ) > self::MAX_ROWS_PER_TABLE ) {
            return null;
        }
        $map = array();
        foreach ( $rows as $row ) {
            if ( ! isset( $row['key_hash'], $row['row_hash'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $row['key_hash'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $row['row_hash'] ) ||
                isset( $map[ $row['key_hash'] ] ) ) {
                return null;
            }
            $map[ $row['key_hash'] ] = $row['row_hash'];
        }
        return $map;
    }

    private static function compare_rows( $source, $target, $source_table, $destination_map, $primary ) {
        if ( empty( $source_table['columns'] ) || count( $source_table['rows'] ) > self::MAX_ROWS_PER_TABLE ) {
            return self::unavailable( $source, $target, 'source_row_limit_exceeded_or_schema_missing' );
        }
        $columns = $source_table['columns'];
        foreach ( $source_table['rows'] as $row ) {
            if ( count( $row ) !== count( $columns ) ) {
                return self::unavailable( $source, $target, 'source_row_column_count_mismatch' );
            }
        }
        $source_map = self::row_map( $source_table['rows'], $columns, $primary );
        if ( $source_map === null ) {
            return self::unavailable( $source, $target, 'duplicate_or_null_primary_key' );
        }
        $added = 0;
        $changed = 0;
        foreach ( $source_map as $key => $row_hash ) {
            if ( ! array_key_exists( $key, $destination_map ) ) {
                $added++;
            } elseif ( ! hash_equals( $destination_map[ $key ], $row_hash ) ) {
                $changed++;
            }
        }
        $removed = count( array_diff_key( $destination_map, $source_map ) );
        return array(
            'source_table' => $source,
            'destination_table' => $target,
            'status' => 'compared',
            'source_rows' => count( $source_map ),
            'destination_rows' => count( $destination_map ),
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged' => count( $source_map ) - $added - $changed,
            'destination_fingerprint' => self::fingerprint( $destination_map, $columns, $primary ),
        );
    }

    /** Stable opaque fingerprint for detecting writes after the UI preview. */
    public static function fingerprint( $row_map, $columns, $primary ) {
        if ( ! is_array( $row_map ) ) {
            throw new InvalidArgumentException( 'A destination row map is required.' );
        }
        ksort( $row_map, SORT_STRING );
        $context = hash_init( 'sha256' );
        hash_update( $context, wp_json_encode( array( 'columns' => $columns, 'primary_key' => $primary ) ) . "\0" );
        foreach ( $row_map as $key => $digest ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ||
                ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
                throw new InvalidArgumentException( 'A destination row map is malformed.' );
            }
            hash_update( $context, $key . $digest );
        }
        return hash_final( $context );
    }

    /** Fingerprint live tables only; used immediately before selective import. */
    public static function live_fingerprints( $suffixes ) {
        global $wpdb;
        if ( ! is_array( $suffixes ) || empty( $suffixes ) || count( $suffixes ) > self::MAX_TABLES || is_multisite() ) {
            throw new InvalidArgumentException( 'A bounded single-site table selection is required.' );
        }
        $result = array();
        foreach ( array_values( array_unique( $suffixes ) ) as $suffix ) {
            if ( ! is_string( $suffix ) || ! preg_match( '/^[A-Za-z0-9_$]{1,180}$/D', $suffix ) ||
                strpos( $suffix, 'basesite_' ) === 0 || preg_match( '/^[0-9]+_/', $suffix ) ) {
                throw new InvalidArgumentException( 'A selected table suffix is not safely mappable.' );
            }
            $target = $wpdb->prefix . $suffix;
            if ( strlen( $target ) > 64 ) {
                throw new RuntimeException( 'A destination table name exceeds the MySQL identifier limit.' );
            }
            $columns = self::destination_columns( $target );
            $primary = $columns === null ? array() : self::destination_primary_key( $target );
            if ( empty( $columns ) || empty( $primary ) || array_diff( $primary, $columns ) ) {
                $result[ $suffix ] = null;
                continue;
            }
            $rows = self::destination_rows( $target, $columns, $primary );
            $result[ $suffix ] = $rows === null ? null : self::fingerprint( $rows, $columns, $primary );
        }
        return $result;
    }

    private static function row_map( $rows, $columns, $primary ) {
        $indexes = array();
        foreach ( $primary as $name ) {
            $index = array_search( $name, $columns, true );
            if ( $index === false ) { return null; }
            $indexes[] = $index;
        }
        $map = array();
        foreach ( $rows as $row ) {
            $key_values = array();
            foreach ( $indexes as $index ) {
                if ( ! array_key_exists( $index, $row ) || $row[ $index ] === null ) { return null; }
                $key_values[] = (string) $row[ $index ];
            }
            $key = hash( 'sha256', self::encode_values( $key_values ) );
            if ( isset( $map[ $key ] ) ) { return null; }
            $map[ $key ] = hash( 'sha256', self::encode_values( $row ) );
        }
        return $map;
    }

    private static function encode_values( $values ) {
        $encoded = '';
        foreach ( $values as $value ) {
            if ( $value === null ) {
                $encoded .= "\0";
                continue;
            }
            $value = (string) $value;
            if ( strlen( $value ) > 4294967295 ) {
                throw new RuntimeException( 'A SQL value is too large for canonical row hashing.' );
            }
            $encoded .= "\1" . pack( 'N', strlen( $value ) ) . $value;
        }
        return $encoded;
    }

    private static function sql_value_digest_part( $column ) {
        $quoted = self::quote_identifier( $column );
        return "IF({$quoted} IS NULL, UNHEX('00'), CONCAT(UNHEX('01'), " .
            "UNHEX(LPAD(HEX(OCTET_LENGTH(CAST({$quoted} AS BINARY))), 8, '0')), " .
            "CAST({$quoted} AS BINARY)))";
    }

    private static function row_bytes( $row ) {
        $bytes = 0;
        foreach ( $row as $value ) {
            if ( is_string( $value ) ) { $bytes += strlen( $value ); }
        }
        return $bytes;
    }

    private static function quote_identifier( $identifier ) {
        if ( ! is_string( $identifier ) || ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/D', $identifier ) ) {
            throw new RuntimeException( 'Unsafe SQL identifier.' );
        }
        return '`' . $identifier . '`';
    }

    private static function unavailable( $source, $target, $reason ) {
        return array(
            'source_table' => $source,
            'destination_table' => $target,
            'status' => 'unavailable',
            'reason' => $reason,
        );
    }
}
