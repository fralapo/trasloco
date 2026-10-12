<?php
/**
 * Persistent, single-owner job ledger used by upcoming asynchronous stages.
 *
 * A job is not a queue worker by itself. A future worker can atomically claim
 * it, checkpoint progress and release ownership. Every mutating operation is
 * conditional on its lease owner so two workers cannot finish the same job.
 */
class Trasloco_Job_Store {
    const SCHEMA = 1;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'trasloco_jobs';
    }

    public static function maybe_install() {
        $version = (int) get_option( 'trasloco_jobs_schema_version', 0 );
        if ( $version >= self::SCHEMA ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(32) NOT NULL,
            idempotency_key varchar(64) NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'pending',
            progress tinyint(3) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            checkpoint longtext NOT NULL,
            lease_owner varchar(64) NOT NULL DEFAULT '',
            lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            failure text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY job_state (state)
        ) {$charset};" );
        // Only publish the schema marker after the table actually exists.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            update_option( 'trasloco_jobs_schema_version', self::SCHEMA, false );
        }
    }

    public static function create( $kind, $payload, $key ) {
        global $wpdb;
        self::maybe_install();
        if ( ! in_array( $kind, array( 'backup', 'restore', 'verify', 'transfer' ), true ) ||
            ! is_array( $payload ) || ! is_string( $key ) ||
            ! preg_match( '/^[a-zA-Z0-9:_-]{12,64}$/D', $key ) ) {
            return new WP_Error( 'trasloco_invalid_job', 'Invalid job request.' );
        }
        $table = self::table();
        $now = gmdate( 'Y-m-d H:i:s' );
        $encoded = wp_json_encode( $payload );
        if ( ! is_string( $encoded ) ) {
            return new WP_Error( 'trasloco_invalid_job', 'The job payload could not be encoded.' );
        }
        $inserted = $wpdb->insert( $table, array(
            'kind' => $kind,
            'idempotency_key' => $key,
            'state' => 'pending',
            'payload' => $encoded,
            'checkpoint' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ), array( '%s','%s','%s','%s','%s','%s','%s' ) );

        // On a duplicate key, only reuse the existing job for the *same*
        // operation and payload. Otherwise an accidental key reuse could
        // return the status of a different archive or backup operation.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, kind, payload FROM {$table} WHERE idempotency_key = %s", $key
        ), ARRAY_A );
        if ( ! $existing ) {
            return new WP_Error( 'trasloco_job_store_error', 'Unable to save the job.' );
        }
        if ( $existing['kind'] !== $kind || $existing['payload'] !== $encoded ) {
            return new WP_Error( 'trasloco_idempotency_conflict',
                'This idempotency key belongs to another operation.',
                array( 'status' => 409 )
            );
        }
        return (int) $existing['id'];
    }

    public static function get( $id ) {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, kind, state, progress, checkpoint, lease_owner, lease_until, attempts, failure FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['progress'] = (int) $row['progress'];
        $row['attempts'] = (int) $row['attempts'];
        $row['checkpoint'] = json_decode( $row['checkpoint'], true );
        // Secret-bearing payloads are deliberately not exposed by get().
        return $row;
    }


    /**
     * Internal use only. Never expose serialized payload in REST responses.
     */
    public static function workload( $id ) {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, kind, state, payload, attempts FROM {$table} WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $row['payload'] = json_decode( $row['payload'], true );
        return $row;
    }

    /** Cancel a pending or running job; running workers lose commit permission. */
    public static function cancel( $id ) {
        global $wpdb;
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'cancelled', lease_owner = '', lease_until = 0,
            updated_at = %s WHERE id = %d AND state IN ('pending', 'running')",
            gmdate( 'Y-m-d H:i:s' ), $id
        ) );
    }

    /** Extend an owned lease during streamed work. */
    public static function renew( $id, $worker, $lease_seconds = 600 ) {
        global $wpdb;
        if ( ! is_int( $lease_seconds ) || $lease_seconds < 30 || $lease_seconds > 3600 ) {
            return false;
        }
        $now = time();
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET lease_until = GREATEST(lease_until + 1, %d), updated_at = %s
            WHERE id = %d AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            $now + $lease_seconds, gmdate( 'Y-m-d H:i:s' ), $id, $worker, $now
        ) );
    }


    /** Recoverable verification jobs; destructive backups are never replayed. */
    public static function recoverable_verifications( $limit = 10 ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 50, (int) $limit ) );
        $now = time();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE kind = 'verify' AND
            (state = 'pending' OR (state = 'running' AND lease_until < %d))
            ORDER BY id ASC LIMIT %d",
            $now, $limit
        ) ) );
    }

    /** Mark abandoned backup workers as failed, rather than duplicating a
     * potentially multi-gigabyte operation automatically. */
    public static function fail_abandoned_backups() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', lease_owner = '', lease_until = 0,
            failure = 'Backup worker lease expired; inspect files before retrying.',
            updated_at = %s WHERE kind = 'backup' AND state = 'running' AND lease_until < %d",
            gmdate( 'Y-m-d H:i:s' ), time()
        ) );
    }

    /** Reopen failed/cancelled verification jobs (at most three attempts).
     * Backups and restores are intentionally excluded to avoid duplicates. */
    public static function retry_verification( $id ) {
        global $wpdb;
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'pending', failure = NULL,
            lease_owner = '', lease_until = 0, updated_at = %s
            WHERE id = %d AND kind = 'verify' AND attempts < 3
            AND state IN ('failed','cancelled')",
            gmdate( 'Y-m-d H:i:s' ), $id
        ) );
    }

    /** Bounded recent job listing; excludes payloads, credentials and paths. */
    public static function recent( $limit = 20 ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 100, (int) $limit ) );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT %d", $limit
        ) );
        $result = array();
        foreach ( $ids as $id ) {
            $job = self::get( (int) $id );
            if ( $job !== null ) {
                $result[] = $job;
            }
        }
        return $result;
    }

    public static function claim( $id, $worker, $lease_seconds = 300 ) {
        global $wpdb;
        if ( ! is_string( $worker ) || ! preg_match( '/^[a-zA-Z0-9:_-]{12,64}$/D', $worker ) ||
            ! is_int( $lease_seconds ) || $lease_seconds < 30 || $lease_seconds > 3600 ) {
            return false;
        }
        $now = time();
        $table = self::table();
        // Atomic acquisition, including reclaiming expired leases.
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'running', lease_owner = %s, lease_until = %d,
            attempts = attempts + 1, updated_at = %s
            WHERE id = %d AND (state = 'pending' OR (state = 'running' AND lease_until < %d))",
            $worker, $now + $lease_seconds, gmdate( 'Y-m-d H:i:s' ), $id, $now
        ) );
    }

    public static function checkpoint( $id, $worker, $progress, $data = array() ) {
        global $wpdb;
        if ( ! is_int( $progress ) || $progress < 0 || $progress > 100 || ! is_array( $data ) ) {
            return false;
        }
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET progress = %d, checkpoint = %s, updated_at = %s
            WHERE id = %d AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            $progress, wp_json_encode( $data ), gmdate( 'Y-m-d H:i:s' ), $id, $worker, time()
        ) );
    }

    public static function finish( $id, $worker ) {
        global $wpdb;
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'completed', progress = 100,
            lease_until = 0, lease_owner = '', updated_at = %s
            WHERE id = %d AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            gmdate( 'Y-m-d H:i:s' ), $id, $worker, time()
        ) );
    }

    public static function fail( $id, $worker, $error ) {
        global $wpdb;
        $table = self::table();
        $message = substr( sanitize_text_field( $error ), 0, 255 );
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', failure = %s,
            lease_until = 0, lease_owner = '', updated_at = %s
            WHERE id = %d AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            $message, gmdate( 'Y-m-d H:i:s' ), $id, $worker, time()
        ) );
    }
}
