<?php
/**
 * Admin-only REST visibility and bounded backup job mutations.
 * WordPress cookie+REST nonces or revocable Application Passwords authenticate
 * callers; every route independently requires manage_options.
 */
class Trasloco_REST_API {
    /** Register the optional WordPress Abilities API category (Core 6.9+). */
    public static function register_ability_category() {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }
        wp_register_ability_category( 'trasloco', array(
            'label' => __( 'Trasloco', TRASLOCO_PLUGIN_NAME ),
            'description' => __( 'Read-only backup and recovery readiness information.', TRASLOCO_PLUGIN_NAME ),
        ) );
    }

    /** Expose the existing bounded preflight as an admin-only read-only ability. */
    public static function register_site_readiness_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        wp_register_ability( 'trasloco/site-readiness', array(
            'category' => 'trasloco',
            'label' => __( 'Inspect Trasloco site readiness', TRASLOCO_PLUGIN_NAME ),
            'description' => __( 'Returns advisory backup storage, server capability and cron diagnostics.', TRASLOCO_PLUGIN_NAME ),
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'ready_for_local_backup' => array( 'type' => 'boolean' ),
                    'backup_directory' => array( 'type' => 'string' ),
                    'backup_web_access' => array( 'type' => 'string' ),
                    'free_space_bytes' => array( 'anyOf' => array(
                        array( 'type' => 'integer' ), array( 'type' => 'null' ),
                    ) ),
                    'php_64_bit' => array( 'type' => 'boolean' ),
                    'mysqli_available' => array( 'type' => 'boolean' ),
                    'sodium_available' => array( 'type' => 'boolean' ),
                    'wp_cron' => array( 'type' => 'string' ),
                    'issues' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    'warning' => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback' => array( __CLASS__, 'site_readiness' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'meta' => array(
                'show_in_rest' => true,
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    /** Return the same advisory report as /trasloco/v1/preflight. */
    public static function site_readiness() {
        return Trasloco_Preflight::report();
    }

    public static function register() {
        register_rest_route( 'trasloco/v1', '/recovery-points', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_recovery_points' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/preflight', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'preflight' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/health', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_health' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_backups' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)/preview', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'preview_backup' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+\.wpress)/manifest', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'transfer_manifest' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+\.wpress)/chunk', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'transfer_chunk' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'offset' => array( 'required' => true, 'type' => 'integer', 'minimum' => 0 ) ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)/verify', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'queue_verification' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'archive' => array( 'required' => true, 'type' => 'string' ) ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_jobs' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/backup', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'queue_backup' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array(
                'retention' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
                'upload_offsite' => array( 'type' => 'boolean' ),
                'idempotency_key' => array( 'type' => 'string', 'maxLength' => 64 ),
            ),
        ) );
        register_rest_route( 'trasloco/v1', '/audit', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'list_audit_events' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/(?P<id>[0-9]+)/retry', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'retry_job' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/(?P<id>[0-9]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_job' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/jobs/(?P<id>[0-9]+)/cancel', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'cancel_job' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
        ) );
        register_rest_route( 'trasloco/v1', '/backups/(?P<archive>[A-Za-z0-9_.-]+)', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_backup' ),
            'permission_callback' => array( __CLASS__, 'can_manage' ),
            'args' => array( 'archive' => array( 'required' => true, 'type' => 'string' ) ),
        ) );
    }


    public static function queue_verification( $request ) {
        $archive = $request->get_param( 'archive' );
        try {
            $path = trasloco_backup_path( array( 'archive' => $archive ) );
            if ( ! is_file( $path ) || ! is_readable( $path ) ) {
                return new WP_Error( 'trasloco_not_found', 'Backup not found.', array( 'status' => 404 ) );
            }
        } catch ( Exception $e ) {
            return new WP_Error( 'trasloco_invalid_archive', 'Invalid archive filename.', array( 'status' => 400 ) );
        }
        // Every user action starts a new verification; a retrying caller may
        // provide one stable idempotency key in the REST request body.
        $key = $request->get_param( 'idempotency_key' );
        if ( $key === null || $key === '' ) {
            $key = wp_generate_password( 32, false, false );
        }
        $id = Trasloco_Job_Store::create( 'verify', array( 'archive' => $archive ), $key );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( ! Trasloco_Audit_Log::record( 'verification.queued', $id ) ) {
            return new WP_Error( 'trasloco_audit_failed', 'Verification was saved but could not be added to the audit log.', array( 'status' => 503, 'job_id' => $id ) );
        }
        if ( ! Trasloco_Job_Worker::schedule( $id ) ) {
            return new WP_Error( 'trasloco_schedule_failed', 'Job saved but no worker could be scheduled. Use WP-CLI to run it.', array( 'status' => 503, 'job_id' => $id ) );
        }
        return new WP_REST_Response( array( 'job_id' => $id, 'state' => Trasloco_Job_Store::get( $id )['state'] ), 202 );
    }

    /** Queue one validated site backup through the persistent worker. */
    public static function queue_backup( $request ) {
        $retention = $request->get_param( 'retention' );
        $retention = $retention === null ? Trasloco_Scheduled_Backups::settings()['keep'] : filter_var(
            $retention, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 100 ) )
        );
        $upload_offsite = $request->get_param( 'upload_offsite' );
        $upload_offsite = $upload_offsite === null ? false : $upload_offsite;
        if ( $retention === false || ! is_int( $retention ) || ! is_bool( $upload_offsite ) ) {
            return new WP_Error( 'trasloco_invalid_backup_job', 'Retention must be 1–100 and upload_offsite must be a boolean.', array( 'status' => 400 ) );
        }
        $key = $request->get_param( 'idempotency_key' );
        if ( $key === null || $key === '' ) {
            $key = wp_generate_password( 40, false, false );
        }
        $id = Trasloco_Job_Store::create( 'backup', array(
            'retention' => $retention,
            'upload_offsite' => $upload_offsite,
        ), $key );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( ! Trasloco_Audit_Log::record( 'backup.queued', $id ) ) {
            return new WP_Error( 'trasloco_audit_failed', 'Backup was saved but could not be added to the audit log.', array( 'status' => 503, 'job_id' => $id ) );
        }
        if ( ! Trasloco_Job_Worker::schedule( $id ) ) {
            return new WP_Error( 'trasloco_schedule_failed', 'Backup job was saved but no worker could be scheduled.', array( 'status' => 503, 'job_id' => $id ) );
        }
        return new WP_REST_Response( array(
            'job_id' => $id,
            'state' => Trasloco_Job_Store::get( $id )['state'],
        ), 202 );
    }

    public static function list_audit_events( $request ) {
        $raw_limit = $request->get_param( 'per_page' );
        $limit = $raw_limit === null ? 100 : filter_var( $raw_limit, FILTER_VALIDATE_INT,
            array( 'options' => array( 'min_range' => 1, 'max_range' => 100 ) ) );
        if ( $limit === false ) {
            return new WP_Error( 'trasloco_invalid_pagination', 'per_page must be 1–100.', array( 'status' => 400 ) );
        }
        return rest_ensure_response( array( 'events' => Trasloco_Audit_Log::recent( $limit ) ) );
    }


    public static function list_jobs( $request ) {
        return rest_ensure_response( array( 'jobs' => Trasloco_Job_Store::recent( 20 ) ) );
    }

    public static function retry_job( $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! Trasloco_Job_Store::retry_verification( $id ) ) {
            return new WP_Error( 'trasloco_retry_not_allowed', 'Only failed or cancelled verification jobs may be retried (up to three attempts).', array( 'status' => 409 ) );
        }
        if ( ! Trasloco_Audit_Log::record( 'job.retried', $id ) ) {
            return new WP_Error( 'trasloco_audit_failed', 'Retry was recorded but could not be added to the audit log.', array( 'status' => 503 ) );
        }
        if ( ! Trasloco_Job_Worker::schedule( $id ) ) {
            return new WP_Error( 'trasloco_schedule_failed', 'Retry recorded but worker scheduling failed.', array( 'status' => 503 ) );
        }
        return new WP_REST_Response( Trasloco_Job_Store::get( $id ), 202 );
    }

    public static function get_job( $request ) {
        $job = Trasloco_Job_Store::get( (int) $request->get_param( 'id' ) );
        return $job === null
            ? new WP_Error( 'trasloco_job_not_found', 'Job not found.', array( 'status' => 404 ) )
            : rest_ensure_response( $job );
    }

    public static function cancel_job( $request ) {
        $id = (int) $request->get_param( 'id' );
        if ( ! Trasloco_Job_Store::cancel( $id ) ) {
            return new WP_Error( 'trasloco_job_not_cancellable', 'Job not found or already completed.', array( 'status' => 409 ) );
        }
        if ( ! Trasloco_Audit_Log::record( 'job.cancelled', $id ) ) {
            return new WP_Error( 'trasloco_audit_failed', 'Cancellation was recorded but could not be added to the audit log.', array( 'status' => 503 ) );
        }
        return rest_ensure_response( Trasloco_Job_Store::get( $id ) );
    }


    /**
     * Remote monitoring endpoint for future multi-site/agency consoles.
     * Does not return credentials, filesystem paths or user account details.
     * A sidecar only means "manifest available", never "verified".
     */
    public static function get_health( $request ) {
        global $wpdb;
        $backups = (new Trasloco_Backups())->get_files();
        $latest = ! empty( $backups ) ? $backups[0] : null;
        $schedule = Trasloco_Scheduled_Backups::settings();
        $table = Trasloco_Job_Store::table();
        $failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state = 'failed'" );
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state IN ('pending','running')" );
        return rest_ensure_response( array(
            'site' => home_url(),
            'backups' => count( $backups ),
            'latest_backup' => $latest ? self::metadata( $latest['filename'] ) : null,
            'schedule' => $schedule['frequency'],
            'next_scheduled_at' => $schedule['frequency'] === 'off' ? null : wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ),
            'failed_jobs' => $failed,
            'active_jobs' => $pending,
        ) );
    }


    public static function preview_backup( $request ) {
        try {
            $path = trasloco_backup_path( array( 'archive' => $request->get_param( 'archive' ) ) );
            $report = Trasloco_Archive_Inspector::inspect( $path, 30 );
            $report['database_preview'] = self::database_preview( $path );
            return rest_ensure_response( $report );
        } catch ( Exception $e ) {
            return new WP_Error( 'trasloco_invalid_preview', 'The archive could not be inspected.', array( 'status' => 400 ) );
        }
    }

    /** Compare recognized archive table operations to destination table names only. */
    private static function database_preview( $archive ) {
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        if ( Trasloco_Integrity::verify( $archive )['status'] !== 'verified' ) {
            return array(
                'status' => 'unverified',
                'table_operations' => array(),
                'warning' => 'A valid SHA-256 manifest is required for the SQL operation preview.',
            );
        }
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-offline-sql-preview.php';
        try {
            $inventory = Trasloco_Offline_SQL_Preview::preview( $archive );
        } catch ( Throwable $error ) {
            return array(
                'status' => 'unavailable',
                'table_operations' => array(),
                'warning' => 'The SQL operation inventory could not be safely produced.',
            );
        }
        global $wpdb;
        $destination_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! is_array( $destination_tables ) ) {
            return array(
                'status' => 'destination_unavailable',
                'table_operations' => array(),
                'warning' => 'Destination table names could not be read.',
            );
        }
        $operations = array();
        foreach ( $inventory['tables'] as $source_table => $actions ) {
            $destination_table = self::preview_destination_table( $source_table );
            $exists = $destination_table === null ? null : in_array( $destination_table, $destination_tables, true );
            if ( $exists === null ) {
                $expected_effect = 'unknown_mapping';
            } elseif ( $actions['drop'] > 0 && $actions['create'] > 0 ) {
                $expected_effect = 'replace_existing_table';
            } elseif ( ! $exists && $actions['create'] > 0 ) {
                $expected_effect = 'create_table';
            } elseif ( $exists ) {
                $expected_effect = 'modify_existing_table';
            } else {
                $expected_effect = 'unknown_effect';
            }
            $operations[] = array(
                'source_table' => $source_table,
                'destination_table' => $destination_table,
                'destination_exists' => $exists,
                'expected_effect' => $expected_effect,
                'actions' => $actions,
            );
        }
        return array(
            'status' => 'available',
            'tables_seen' => $inventory['tables_seen'],
            'table_operations' => $operations,
            'warning' => 'Table-level operation preview only. It does not compare row values or counts, validate arbitrary SQL, or predict plugin/application behavior. Import may replace the listed destination tables. This is advisory, not restore approval.',
        );
    }

    /** Predict table names only for the single-site placeholder mapping. */
    private static function preview_destination_table( $source_table ) {
        global $wpdb;
        $placeholder = 'SERVMASK_PREFIX_';
        if ( ! is_string( $source_table ) || strpos( $source_table, $placeholder ) !== 0 || is_multisite() ) {
            return null;
        }
        $suffix = substr( $source_table, strlen( $placeholder ) );
        if ( $suffix === '' || strpos( $suffix, 'basesite_' ) === 0 || preg_match( '/^[0-9]+_/', $suffix ) ) {
            return null;
        }
        return $wpdb->prefix . $suffix;
    }

    public static function preflight( $request ) {
        return rest_ensure_response( Trasloco_Preflight::report() );
    }


    /** Read-only recovery journal inventory for site administrators only. */
    public static function list_recovery_points( $request ) {
        try {
            $records = Trasloco_Recovery_Journal::entries( TRASLOCO_BACKUPS_PATH, 100 );
        } catch ( Throwable $e ) {
            return new WP_Error( 'trasloco_recovery_unavailable',
                'Recovery receipts could not be read.', array( 'status' => 503 ) );
        }
        $pending = array_values( array_filter( $records, function ( $entry ) {
            return $entry['status'] === 'pending';
        } ) );
        return rest_ensure_response( array(
            'pending' => $pending,
            'limit' => 100,
            'warning' => 'A pending receipt is not proof of recoverability or an automatic rollback.',
        ) );
    }

    public static function can_manage() {
        return current_user_can( 'manage_options' );
    }

    /** Return bounded sidecar metadata; the receiver verifies every archive byte. */
    public static function transfer_manifest( $request ) {
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        try {
            $name = (string) $request->get_param( 'archive' );
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) || strpos( $name, '..' ) !== false ) {
                return new WP_Error( 'trasloco_invalid_archive', 'Invalid archive filename.', array( 'status' => 400 ) );
            }
            $path = trasloco_backup_path( array( 'archive' => $name ) );
            if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
                return new WP_Error( 'trasloco_not_found', 'Backup not found.', array( 'status' => 404 ) );
            }
            $sidecar_path = Trasloco_Integrity::manifest_path( $path );
            if ( is_link( $sidecar_path ) || ! is_file( $sidecar_path ) || ! is_readable( $sidecar_path ) ||
                filesize( $sidecar_path ) > Trasloco_Integrity::MAX_MANIFEST_BYTES ) {
                return new WP_Error( 'trasloco_unverified_backup', 'Backup manifest is unavailable.', array( 'status' => 409 ) );
            }
            $sidecar = json_decode( (string) file_get_contents( $sidecar_path ), true );
            if ( ! is_array( $sidecar ) ) {
                return new WP_Error( 'trasloco_unverified_backup', 'Backup manifest is invalid.', array( 'status' => 409 ) );
            }
            if ( isset( $sidecar['manifest_version'] ) && in_array( $sidecar['manifest_version'], array( 1, 2 ), true ) &&
                isset( $sidecar['algorithm'], $sidecar['size_bytes'], $sidecar['sha256'] ) && $sidecar['algorithm'] === 'sha256' ) {
                $expected_bytes = $sidecar['size_bytes'];
                $expected_digest = $sidecar['sha256'];
            } elseif ( isset( $sidecar['schema'], $sidecar['format'], $sidecar['archive'], $sidecar['bytes'], $sidecar['sha256'] ) &&
                $sidecar['schema'] === 1 && $sidecar['format'] === 'wpress' && $sidecar['archive'] === $name ) {
                $expected_bytes = $sidecar['bytes'];
                $expected_digest = $sidecar['sha256'];
            } else {
                return new WP_Error( 'trasloco_unverified_backup', 'Backup manifest is unsupported.', array( 'status' => 409 ) );
            }
            if ( ! is_int( $expected_bytes ) || $expected_bytes <= 0 || $expected_bytes !== filesize( $path ) ||
                ! is_string( $expected_digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_digest ) ) {
                return new WP_Error( 'trasloco_unverified_backup', 'Backup manifest does not match archive metadata.', array( 'status' => 409 ) );
            }
            $response = new WP_REST_Response( array(
                'archive' => $name,
                'bytes' => $expected_bytes,
                'sha256' => $expected_digest,
            ), 200 );
            $response->header( 'Cache-Control', 'private, no-store' );
            return $response;
        } catch ( Throwable $error ) {
            return new WP_Error( 'trasloco_transfer_error', 'Backup transfer refused.', array( 'status' => 400 ) );
        }
    }

    /** Return one bounded fragment from an archive with a valid sidecar. */
    public static function transfer_chunk( $request ) {
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        try {
            $name = (string) $request->get_param( 'archive' );
            $offset = filter_var( $request->get_param( 'offset' ), FILTER_VALIDATE_INT,
                array( 'options' => array( 'min_range' => 0 ) ) );
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) ||
                strpos( $name, '..' ) !== false || $offset === false ) {
                return new WP_Error( 'trasloco_invalid_transfer', 'Invalid archive or transfer offset.', array( 'status' => 400 ) );
            }
            $path = trasloco_backup_path( array( 'archive' => $name ) );
            $manifest = Trasloco_Integrity::manifest_path( $path );
            if ( is_link( $path ) || is_link( $manifest ) || ! is_file( $path ) || ! is_readable( $path ) ||
                ! is_file( $manifest ) || ! is_readable( $manifest ) ) {
                return new WP_Error( 'trasloco_unverified_backup', 'Backup manifest unavailable.', array( 'status' => 409 ) );
            }
            $manifest_data = json_decode( (string) file_get_contents( $manifest ), true );
            $manifest_bytes = is_array( $manifest_data ) && isset( $manifest_data['size_bytes'] )
                ? $manifest_data['size_bytes']
                : ( is_array( $manifest_data ) && isset( $manifest_data['bytes'] ) ? $manifest_data['bytes'] : null );
            if ( ! is_array( $manifest_data ) || ! is_int( $manifest_bytes ) || $manifest_bytes !== filesize( $path ) ||
                ! isset( $manifest_data['sha256'] ) || ! is_string( $manifest_data['sha256'] ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', $manifest_data['sha256'] ) ) {
                return new WP_Error( 'trasloco_unverified_backup', 'Backup manifest is invalid.', array( 'status' => 409 ) );
            }
            $total = $manifest_bytes;
            if ( $offset >= $total || $offset % Trasloco_Peer::CHUNK_BYTES !== 0 ) {
                return new WP_Error( 'trasloco_range_error', 'Invalid archive offset.', array( 'status' => 416 ) );
            }
            $length = min( Trasloco_Peer::CHUNK_BYTES, $total - $offset );
            $stream = @fopen( $path, 'rb' );
            if ( $stream === false ) {
                return new WP_Error( 'trasloco_transfer_error', 'Unable to read archive fragment.', array( 'status' => 503 ) );
            }
            try {
                if ( fseek( $stream, $offset, SEEK_SET ) !== 0 ) {
                    throw new RuntimeException( 'Unable to seek archive fragment.' );
                }
                $data = '';
                while ( strlen( $data ) < $length ) {
                    $part = fread( $stream, $length - strlen( $data ) );
                    if ( $part === false || $part === '' ) {
                        throw new RuntimeException( 'Archive changed during transfer.' );
                    }
                    $data .= $part;
                }
            } finally {
                fclose( $stream );
            }
            $response = new WP_REST_Response( array(
                'offset' => $offset, 'length' => strlen( $data ),
                'sha256' => hash( 'sha256', $data ), 'data' => base64_encode( $data ),
            ), 200 );
            $response->header( 'Cache-Control', 'private, no-store' );
            return $response;
        } catch ( Throwable $error ) {
            return new WP_Error( 'trasloco_transfer_error', 'Unable to transfer this archive fragment.', array( 'status' => 400 ) );
        }
    }

    public static function metadata( $filename ) {
        require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
        $path = trasloco_backup_path( array( 'archive' => $filename ) );
        if ( ! is_file( $path ) ) {
            return null;
        }
        $has_manifest = is_file( Trasloco_Integrity::manifest_path( $path ) );
        // Do not hash huge files during listing: a checksum requires an
        // explicit verify job/CLI operation and must never be implied here.
        return array(
            'filename' => basename( $path ),
            'size_bytes' => (int) filesize( $path ),
            'updated_at' => (int) filemtime( $path ),
            'integrity' => $has_manifest ? 'manifest_available' : 'unverified',
        );
    }

    public static function list_backups( $request ) {
        // Preserve the original first 100 results for existing clients, but
        // allow bounded paging for large multi-client agency installations.
        $raw_page = $request->get_param( 'page' );
        $raw_limit = $request->get_param( 'per_page' );
        $page = $raw_page === null ? 1 : filter_var(
            $raw_page, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 10000 ) )
        );
        $per_page = $raw_limit === null ? 100 : filter_var(
            $raw_limit, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 100 ) )
        );
        if ( $page === false || $per_page === false ) {
            return new WP_Error( 'trasloco_invalid_pagination',
                'page must be 1–10000 and per_page must be 1–100.',
                array( 'status' => 400 ) );
        }
        $model = new Trasloco_Backups();
        $backups = $model->get_files();
        $total = count( $backups );
        $items = array();
        foreach ( array_slice( $backups, ( $page - 1 ) * $per_page, $per_page ) as $backup ) {
            try {
                $metadata = self::metadata( $backup['filename'] );
                if ( $metadata !== null ) {
                    $items[] = $metadata;
                }
            } catch ( Exception $e ) {
                // Malformed archive metadata never discloses server paths.
            }
        }
        $response = rest_ensure_response( array(
            'backups' => $items,
            'limit' => $per_page,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
        ) );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
        return $response;
    }

    public static function get_backup( $request ) {
        $archive = $request->get_param( 'archive' );
        try {
            $metadata = self::metadata( $archive );
        } catch ( Exception $e ) {
            $metadata = null;
        }
        if ( $metadata === null ) {
            return new WP_Error( 'trasloco_backup_not_found', 'Backup not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $metadata );
    }
}
