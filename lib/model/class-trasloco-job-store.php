<?php
/**
 * Persistent, single-owner job ledger for asynchronous backup, verification
 * and resumable transfer stages. Every mutation is conditional on the lease
 * owner so two workers cannot finish the same job.
 */
class Trasloco_Job_Store {
    const SCHEMA = 1;
    const MAX_VERIFY_ATTEMPTS = 3;
    const MAX_TRANSFER_ATTEMPTS = 3;
    const MAX_BACKUP_ATTEMPTS = 3;
    const MAX_CRYPTO_ATTEMPTS = 3;

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
        if ( ! in_array( $kind, array( 'backup', 'restore', 'verify', 'transfer', 'crypto' ), true ) ||
            ! is_array( $payload ) || ! is_string( $key ) ||
            ! preg_match( '/^[a-zA-Z0-9:_-]{12,64}$/D', $key ) ) {
            return new WP_Error( 'trasloco_invalid_job', 'Invalid job request.' );
        }
        // Transfer requests contain names and explicit user consent only; they
        // never persist provider URLs, passwords or other credentials.
        if ( $kind === 'transfer' ) {
            $archive_valid = isset( $payload['archive'] ) && is_string( $payload['archive'] ) &&
                strlen( $payload['archive'] ) <= 206 &&
                preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $payload['archive'] ) &&
                strpos( $payload['archive'], '..' ) === false;
            $download_valid = isset( $payload['operation'] ) && $payload['operation'] === 'download' &&
                count( $payload ) === 2;
            $upload_valid = isset( $payload['operation'], $payload['archive'], $payload['allow_unencrypted'] ) &&
                $payload['operation'] === 'upload' && is_bool( $payload['allow_unencrypted'] ) &&
                ( count( $payload ) === 3 ||
                    ( count( $payload ) === 4 && isset( $payload['delete_after_upload'] ) &&
                        $payload['delete_after_upload'] === true && $payload['allow_unencrypted'] === false &&
                        substr( $payload['archive'], -4 ) === '.enc' ) );
            if ( ! $archive_valid || ( ! $download_valid && ! $upload_valid ) ) {
                return new WP_Error( 'trasloco_invalid_job', 'Invalid or unsupported offsite transfer job.' );
            }
        }
        if ( $kind === 'crypto' ) {
            $expected_suffix = isset( $payload['operation'] ) && $payload['operation'] === 'decrypt'
                ? '.wpress.enc' : '.wpress';
            $valid = isset( $payload['operation'], $payload['archive'] ) &&
                in_array( $payload['operation'], array( 'encrypt', 'decrypt' ), true ) &&
                is_string( $payload['archive'] ) && strlen( $payload['archive'] ) <= 220 &&
                preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $payload['archive'] ) &&
                strpos( $payload['archive'], '..' ) === false && count( $payload ) === 2 &&
                substr( $payload['archive'], -strlen( $expected_suffix ) ) === $expected_suffix;
            if ( ! $valid ) { return new WP_Error( 'trasloco_invalid_job', 'Invalid encryption job.' ); }
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
        $row = self::get_internal( $id );
        if ( is_array( $row ) && isset( $row['checkpoint']['provider_state'] ) ) {
            // Provider upload IDs can authorize multipart operations. Keep
            // them out of REST job status and other public job summaries.
            unset( $row['checkpoint']['provider_state'] );
        }
        if ( is_array( $row ) && $row['kind'] === 'crypto' && is_array( $row['checkpoint'] ) ) {
            $row['checkpoint'] = array_intersect_key( $row['checkpoint'], array_flip( array( 'phase','archive','bytes','total_bytes' ) ) );
        }
        return $row;
    }

    /** Internal worker-only read for trusted provider resume checkpoints. */
    public static function get_internal( $id ) {
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
            "SELECT id FROM {$table} WHERE kind = 'verify' AND attempts < %d AND
            (state = 'pending' OR (state = 'running' AND lease_until < %d))
            ORDER BY id ASC LIMIT %d",
            self::MAX_VERIFY_ATTEMPTS,
            $now, $limit
        ) ) );
    }

    /** Fail crashed verify workers after a bounded number of attempts. */
    public static function fail_exhausted_verifications() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', lease_owner = '', lease_until = 0,
            failure = 'Verification stopped after repeated interrupted attempts.',
            updated_at = %s WHERE kind = 'verify' AND state = 'running'
            AND attempts >= %d AND lease_until < %d",
            gmdate( 'Y-m-d H:i:s' ), self::MAX_VERIFY_ATTEMPTS, time()
        ) );
    }

    /** Recover interrupted offsite downloads; private range checkpoints make replay safe. */
    public static function recoverable_transfers( $limit = 10 ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 50, (int) $limit ) );
        $now = time();
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE kind = 'transfer' AND attempts < %d AND
            (state = 'pending' OR (state = 'running' AND lease_until < %d))
            ORDER BY id ASC LIMIT %d",
            self::MAX_TRANSFER_ATTEMPTS, $now, $limit
        ) ) );
    }

    /** Recover interrupted chunked encryption/decryption from sealed checkpoints. */
    public static function recoverable_crypto( $limit = 10 ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 50, (int) $limit ) );
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE kind = 'crypto' AND attempts < %d AND
            (state = 'pending' OR (state = 'running' AND lease_until < %d))
            ORDER BY id ASC LIMIT %d",
            self::MAX_CRYPTO_ATTEMPTS, time(), $limit
        ) ) );
    }

    public static function fail_exhausted_crypto() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', lease_owner = '', lease_until = 0,
            failure = 'Encryption worker stopped after repeated interrupted attempts.',
            updated_at = %s WHERE kind = 'crypto' AND state = 'running'
            AND attempts >= %d AND lease_until < %d",
            gmdate( 'Y-m-d H:i:s' ), self::MAX_CRYPTO_ATTEMPTS, time()
        ) );
    }

    /** Stop crash-looping downloads after the bounded automatic retry budget. */
    public static function fail_exhausted_transfers() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', lease_owner = '', lease_until = 0,
            failure = 'Offsite download stopped after repeated interrupted attempts.',
            updated_at = %s WHERE kind = 'transfer' AND state = 'running'
            AND attempts >= %d AND lease_until < %d",
            gmdate( 'Y-m-d H:i:s' ), self::MAX_TRANSFER_ATTEMPTS, time()
        ) );
    }

    /** Reopen a failed/cancelled download when its automatic attempt budget remains. */
    public static function retry_transfer( $id ) {
        global $wpdb;
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'pending', failure = NULL,
            lease_owner = '', lease_until = 0, updated_at = %s
            WHERE id = %d AND kind = 'transfer' AND attempts < %d
            AND state IN ('failed','cancelled')",
            gmdate( 'Y-m-d H:i:s' ), $id, self::MAX_TRANSFER_ATTEMPTS
        ) );
    }

    /** Requeue expired workers only when a durable replay checkpoint exists. */
    public static function recoverable_backups( $limit = 10 ) {
        global $wpdb;
        $table = self::table();
        $limit = max( 1, min( 50, (int) $limit ) );
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE kind = 'backup' AND attempts < %d AND
            (state = 'pending' OR (state = 'running' AND checkpoint <> '{}' AND lease_until < %d))
            ORDER BY id ASC LIMIT %d",
            self::MAX_BACKUP_ATTEMPTS, time(), $limit
        ) ) );
    }

    /** Fail an abandoned backup only after its bounded crash-replay budget. */
    public static function fail_abandoned_backups() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', lease_owner = '', lease_until = 0,
            failure = 'Backup worker stopped before a safe replay point or after repeated interrupted attempts; inspect files before retrying.',
            updated_at = %s WHERE kind = 'backup' AND state = 'running' AND lease_until < %d
            AND (checkpoint = '{}' OR attempts >= %d)",
            gmdate( 'Y-m-d H:i:s' ), time(), self::MAX_BACKUP_ATTEMPTS
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
            WHERE id = %d AND kind = 'verify' AND attempts < %d
            AND state IN ('failed','cancelled')",
            gmdate( 'Y-m-d H:i:s' ), $id, self::MAX_VERIFY_ATTEMPTS
        ) );
    }


    /**
     * Normal progress yielding is not a crash. Release ownership and reset the
     * interrupted-attempt counter after atomically publishing the checkpoint.
     * A dead process that never yields will still exhaust its three retries.
     */
    public static function yield_verification( $id, $worker, $progress, $checkpoint ) {
        global $wpdb;
        if ( ! is_int( $progress ) || $progress < 0 || $progress > 99 ||
             ! is_array( $checkpoint ) ) {
            return false;
        }
        $encoded = wp_json_encode( $checkpoint );
        if ( ! is_string( $encoded ) ) {
            return false;
        }
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'pending', progress = %d,
            checkpoint = %s, attempts = 0, lease_owner = '', lease_until = 0,
            updated_at = %s WHERE id = %d AND kind = 'verify'
            AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            $progress, $encoded, gmdate( 'Y-m-d H:i:s' ), $id, $worker, time()
        ) );
    }

    /** Persist one crypto batch and safely release the worker lease. */
    public static function yield_crypto( $id, $worker, $progress, $checkpoint ) {
        global $wpdb;
        if ( ! is_int( $progress ) || $progress < 0 || $progress > 99 || ! is_array( $checkpoint ) ) { return false; }
        $encoded = wp_json_encode( $checkpoint );
        if ( ! is_string( $encoded ) ) { return false; }
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'pending', progress = %d, checkpoint = %s,
            attempts = GREATEST(attempts - 1, 0), lease_owner = '', lease_until = 0,
            failure = NULL, updated_at = %s WHERE id = %d AND kind = 'crypto'
            AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            $progress, $encoded, gmdate( 'Y-m-d H:i:s' ), $id, $worker, time()
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

    /** Return bounded, per-archive verification summaries for the admin list. */
    public static function verification_statuses( $archives ) {
        global $wpdb;
        if ( ! is_array( $archives ) ) {
            return array();
        }
        $wanted = array();
        foreach ( array_slice( $archives, 0, 100 ) as $archive ) {
            if ( is_string( $archive ) && preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $archive ) &&
                strpos( $archive, '..' ) === false ) {
                $wanted[ $archive ] = true;
            }
        }
        if ( empty( $wanted ) ) {
            return array();
        }
        $table = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT state, payload, checkpoint FROM {$table} WHERE kind = %s ORDER BY id DESC LIMIT %d",
            'verify', 1000
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        $statuses = array();
        foreach ( $rows as $row ) {
            $payload = json_decode( $row['payload'], true );
            if ( ! is_array( $payload ) || ! isset( $payload['archive'] ) ||
                ! is_string( $payload['archive'] ) || ! isset( $wanted[ $payload['archive'] ] ) ||
                isset( $statuses[ $payload['archive'] ] ) ) {
                continue;
            }
            $checkpoint = json_decode( $row['checkpoint'], true );
            $proof = is_array( $checkpoint ) && isset( $checkpoint['phase'] ) &&
                $checkpoint['phase'] === 'verified' ? array_intersect_key( $checkpoint, array_flip( array(
                    'phase', 'size_bytes', 'file_mtime', 'file_ctime', 'file_inode', 'file_device',
                    'manifest_size_bytes', 'manifest_mtime', 'manifest_ctime', 'manifest_inode', 'manifest_device',
                ) ) ) : array();
            $statuses[ $payload['archive'] ] = array(
                'state' => sanitize_key( $row['state'] ),
                'proof' => $proof,
            );
        }
        return $statuses;
    }

    public static function claim( $id, $worker, $lease_seconds = 300 ) {
        global $wpdb;
        if ( ! is_string( $worker ) || ! preg_match( '/^[a-zA-Z0-9:_-]{12,64}$/D', $worker ) ||
            ! is_int( $lease_seconds ) || $lease_seconds < 30 || $lease_seconds > 3600 ) {
            return false;
        }
        $now = time();
        $table = self::table();
        // Atomic acquisition. Backups may be reclaimed only after publishing
        // an explicit archive checkpoint; resumable crypto/transfer jobs own
        // their own private checkpoints.
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'running', lease_owner = %s, lease_until = %d,
            attempts = attempts + 1, updated_at = %s
            WHERE id = %d AND (kind NOT IN ('verify','transfer','backup','crypto') OR
                 (kind = 'verify' AND attempts < %d) OR
                 (kind = 'transfer' AND attempts < %d) OR
                 (kind = 'backup' AND attempts < %d) OR
                 (kind = 'crypto' AND attempts < %d))
            AND (state = 'pending' OR
                 ((kind = 'verify' OR kind = 'transfer' OR kind = 'crypto') AND state = 'running' AND lease_until < %d) OR
                 (kind = 'backup' AND checkpoint <> '{}' AND state = 'running' AND lease_until < %d))",
            $worker, $now + $lease_seconds, gmdate( 'Y-m-d H:i:s' ), $id,
            self::MAX_VERIFY_ATTEMPTS, self::MAX_TRANSFER_ATTEMPTS, self::MAX_BACKUP_ATTEMPTS, self::MAX_CRYPTO_ATTEMPTS, $now, $now
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

    /** Publish a durable backup checkpoint and release its lease for replay. */
    public static function yield_backup( $id, $worker, $progress, $checkpoint ) {
        global $wpdb;
        if ( ! is_int( $progress ) || $progress < 0 || $progress > 99 || ! is_array( $checkpoint ) ) {
            return false;
        }
        $table = self::table();
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET state = 'pending', progress = %d, checkpoint = %s,
            lease_owner = '', lease_until = 0, attempts = GREATEST(attempts - 1, 0),
            failure = NULL, updated_at = %s
            WHERE id = %d AND state = 'running' AND lease_owner = %s AND lease_until >= %d",
            $progress, wp_json_encode( $checkpoint ), gmdate( 'Y-m-d H:i:s' ), $id, $worker, time()
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
