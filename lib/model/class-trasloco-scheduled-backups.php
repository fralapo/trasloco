<?php
/** WP-Cron configuration and durable job creation for scheduled backups. */
class Trasloco_Scheduled_Backups {
    const OPTION = 'trasloco_automatic_backup_settings';
    const HOOK = 'trasloco_scheduled_backup_tick';
    const HEALTH_HOOK = 'trasloco_offsite_health_check';
    const NOTIFICATION_HOOK = 'trasloco_retry_notification';
    const HEALTH_OPTION = 'trasloco_offsite_health_state';

    public static function register() {
        add_action( 'init', array( __CLASS__, 'sync' ), 30 );
        add_action( 'admin_post_trasloco_save_schedule', array( __CLASS__, 'save' ) );
        add_action( self::HOOK, array( __CLASS__, 'enqueue' ) );
        add_action( 'trasloco_scheduled_backup_failed', array( __CLASS__, 'notify_failure' ) );
        add_action( 'trasloco_offsite_transfer_failed', array( __CLASS__, 'notify_offsite_failure' ) );
        add_action( self::NOTIFICATION_HOOK, array( __CLASS__, 'retry_notification' ), 10, 3 );
        add_action( 'init', array( __CLASS__, 'sync_health_check' ), 31 );
        add_action( self::HEALTH_HOOK, array( __CLASS__, 'health_check' ) );
    }


    /**
     * Do not email archive contents, stack traces, credentials or internal paths.
     * Delivery depends on the site's mail configuration.
     */
    public static function notify_failure( $job_id ) {
        self::send_alert( 'backup_failed' );
    }

    /** Send a minimal site alert, retrying transient mail failures via WP-Cron. */
    private static function send_alert( $event, $attempt = 0 ) {
        $allowed = array( 'backup_failed', 'offsite_attention', 'offsite_recovered' );
        if ( ! in_array( $event, $allowed, true ) || $attempt < 0 || $attempt > 3 ) {
            return false;
        }
        $settings = self::settings();
        if ( ! $settings['notify_alerts'] ) {
            return false;
        }
        $messages = array(
            'backup_failed' => array(
                __( 'Trasloco automatic backup failed', TRASLOCO_PLUGIN_NAME ),
                __( 'An automatic site backup failed. Open WordPress administration and review Trasloco backups and job status. Your previous backups have not been removed.', TRASLOCO_PLUGIN_NAME ),
            ),
            'offsite_attention' => array(
                __( 'Trasloco offsite storage needs attention', TRASLOCO_PLUGIN_NAME ),
                __( 'Trasloco could not confirm that offsite storage is healthy. Check the provider configuration and recent transfer jobs in WordPress administration.', TRASLOCO_PLUGIN_NAME ),
            ),
            'offsite_recovered' => array(
                __( 'Trasloco offsite storage recovered', TRASLOCO_PLUGIN_NAME ),
                __( 'Trasloco can reach offsite storage again and its archive inventory is complete.', TRASLOCO_PLUGIN_NAME ),
            ),
        );
        $email_ok = self::deliver_email( $event, $attempt, $settings, $messages[ $event ] );
        $webhook_ok = self::deliver_webhook( $event, $attempt, $settings );
        return $email_ok && $webhook_ok;
    }

    /** Schedule one of at most three retries after a channel reports failure. */
    private static function schedule_notification_retry( $channel, $event, $attempt ) {
        if ( $attempt >= 3 ) {
            return;
        }
        $delays = array( 60, 300, 900 );
        wp_schedule_single_event( time() + $delays[ $attempt ], self::NOTIFICATION_HOOK,
            array( $event, $attempt + 1, $channel ) );
    }

    private static function deliver_email( $event, $attempt, $settings, $message ) {
        $email = $settings['notification_email'];
        if ( ! is_email( $email ) ) {
            return true;
        }
        if ( wp_mail( $email, $message[0], $message[1] ) ) {
            return true;
        }
        self::schedule_notification_retry( 'email', $event, $attempt );
        return false;
    }

    /** Send a signed, minimal event payload through WordPress safe HTTP. */
    private static function deliver_webhook( $event, $attempt, $settings ) {
        $url = $settings['webhook_url'];
        $secret = $settings['webhook_secret'];
        if ( $url === '' ) {
            return true;
        }
        if ( ! self::valid_webhook_url( $url ) || strlen( $secret ) < 32 ) {
            return true; // Invalid saved settings are disabled until corrected.
        }
        $timestamp = time();
        $payload = wp_json_encode( array(
            'event' => $event,
            'site' => hash_hmac( 'sha256', home_url( '/' ), $secret ),
            'timestamp' => $timestamp,
            'attempt' => $attempt,
        ) );
        if ( ! is_string( $payload ) ) {
            self::schedule_notification_retry( 'webhook', $event, $attempt );
            return false;
        }
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        $response = wp_safe_remote_post( $url, array(
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 1024,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Trasloco-Event' => $event,
                'X-Trasloco-Timestamp' => (string) $timestamp,
                'X-Trasloco-Signature' => 'sha256=' . $signature,
            ),
            'body' => $payload,
        ) );
        if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) >= 200 &&
             (int) wp_remote_retrieve_response_code( $response ) < 300 ) {
            return true;
        }
        self::schedule_notification_retry( 'webhook', $event, $attempt );
        return false;
    }

    private static function valid_webhook_url( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) &&
            strtolower( $parts['scheme'] ) === 'https' && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['fragment'] );
    }

    /** Retry one channel for known events and bounded attempts only. */
    public static function retry_notification( $event, $attempt, $channel = 'email' ) {
        if ( ! is_string( $event ) || ! is_int( $attempt ) || ! is_string( $channel ) ||
             ! in_array( $channel, array( 'email', 'webhook' ), true ) || $attempt < 1 || $attempt > 3 ) {
            return false;
        }
        $settings = self::settings();
        if ( ! $settings['notify_alerts'] ) {
            return false;
        }
        $messages = array(
            'backup_failed' => array(
                __( 'Trasloco automatic backup failed', TRASLOCO_PLUGIN_NAME ),
                __( 'An automatic site backup failed. Open WordPress administration and review Trasloco backups and job status. Your previous backups have not been removed.', TRASLOCO_PLUGIN_NAME ),
            ),
            'offsite_attention' => array(
                __( 'Trasloco offsite storage needs attention', TRASLOCO_PLUGIN_NAME ),
                __( 'Trasloco could not confirm that offsite storage is healthy. Check the provider configuration and recent transfer jobs in WordPress administration.', TRASLOCO_PLUGIN_NAME ),
            ),
            'offsite_recovered' => array(
                __( 'Trasloco offsite storage recovered', TRASLOCO_PLUGIN_NAME ),
                __( 'Trasloco can reach offsite storage again and its archive inventory is complete.', TRASLOCO_PLUGIN_NAME ),
            ),
        );
        if ( ! isset( $messages[ $event ] ) ) {
            return false;
        }
        return $channel === 'email'
            ? self::deliver_email( $event, $attempt, $settings, $messages[ $event ] )
            : self::deliver_webhook( $event, $attempt, $settings );
    }

    /** Notify only on a health-state transition; never include credentials or endpoints. */
    private static function set_health_state( $state ) {
        $previous = get_option( self::HEALTH_OPTION, 'unknown' );
        if ( $previous === $state ) {
            return;
        }
        update_option( self::HEALTH_OPTION, $state, false );
        if ( $state === 'attention' ) {
            self::send_alert( 'offsite_attention' );
        } elseif ( $state === 'healthy' && $previous === 'attention' ) {
            self::send_alert( 'offsite_recovered' );
        }
    }

    public static function notify_offsite_failure( $job_id ) {
        self::set_health_state( 'attention' );
    }

    /** Schedule a bounded daily inventory check only when offsite delivery is enabled. */
    public static function sync_health_check() {
        $next = wp_next_scheduled( self::HEALTH_HOOK );
        if ( ! self::settings()['upload_offsite'] ) {
            if ( $next !== false ) {
                wp_clear_scheduled_hook( self::HEALTH_HOOK );
            }
            return;
        }
        if ( $next === false ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HEALTH_HOOK );
        }
    }

    /** Probe provider reachability and archive/manifest pairing without reading archive data. */
    public static function health_check() {
        if ( ! self::settings()['upload_offsite'] ) {
            return false;
        }
        try {
            if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
                throw new RuntimeException( 'Backup directory is unavailable.' );
            }
            require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
            $adapter = Trasloco_Storage_Factory::configured();
            $transfer = new Trasloco_Offsite_Transfer( $adapter, TRASLOCO_BACKUPS_PATH );
            $report = $transfer->inventory_status();
            self::set_health_state( $report['status'] === 'healthy' ? 'healthy' : 'attention' );
            return $report;
        } catch ( Throwable $error ) {
            self::set_health_state( 'attention' );
            return false;
        }
    }

    public static function settings() {
        $value = get_option( self::OPTION, array() );
        $value = is_array( $value ) ? $value : array();
        $frequency = isset( $value['frequency'] ) ? $value['frequency'] : 'off';
        $keep = isset( $value['keep'] ) ? (int) $value['keep'] : 7;
        $notification_email = isset( $value['notification_email'] ) && is_string( $value['notification_email'] )
            ? $value['notification_email'] : get_option( 'admin_email' );
        if ( ! is_email( $notification_email ) ) {
            $notification_email = get_option( 'admin_email' );
        }
        $webhook_url = isset( $value['webhook_url'] ) && is_string( $value['webhook_url'] ) &&
            self::valid_webhook_url( $value['webhook_url'] ) ? $value['webhook_url'] : '';
        $webhook_secret = isset( $value['webhook_secret'] ) && is_string( $value['webhook_secret'] ) &&
            strlen( $value['webhook_secret'] ) >= 32 && strlen( $value['webhook_secret'] ) <= 128
            ? $value['webhook_secret'] : '';
        if ( ! in_array( $frequency, array( 'off', 'hourly', 'daily', 'weekly', 'monthly' ), true ) ) {
            $frequency = 'off';
        }
        return array(
            'frequency' => $frequency,
            'keep' => max( 1, min( 100, $keep ) ),
            'before_updates' => ! empty( $value['before_updates'] ),
            'upload_offsite' => ! empty( $value['upload_offsite'] ),
            'notify_alerts' => ! array_key_exists( 'notify_alerts', $value ) || ! empty( $value['notify_alerts'] ),
            'notification_email' => $notification_email,
            'webhook_url' => $webhook_url,
            'webhook_secret' => $webhook_secret,
        );
    }

    public static function sync() {
        $frequency = self::settings()['frequency'];
        $next = wp_next_scheduled( self::HOOK );
        $current = $next === false ? false : wp_get_schedule( self::HOOK );
        // An existing event keeps its old recurrence until explicitly replaced.
        if ( $frequency === 'off' ) {
            if ( $next !== false ) {
                wp_clear_scheduled_hook( self::HOOK );
            }
            return;
        }
        $desired = $frequency === 'monthly' ? false : $frequency;
        if ( $next !== false && $current !== $desired ) {
            wp_clear_scheduled_hook( self::HOOK );
            $next = false;
        }
        if ( $next === false ) {
            if ( $frequency === 'monthly' ) {
                // WordPress-site local calendar months; never pretend a month
                // is exactly 30 days. DST transitions are applied by PHP.
                $next_month = new DateTimeImmutable( 'first day of next month 03:00:00', wp_timezone() );
                wp_schedule_single_event( $next_month->getTimestamp(), self::HOOK );
            } else {
                $seconds = array( 'hourly' => HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS );
                wp_schedule_event( time() + $seconds[ $frequency ], $frequency, self::HOOK );
            }
        }
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not permitted.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'trasloco_save_schedule' );
        $frequency = isset( $_POST['frequency'] ) && is_string( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'off';
        $keep = isset( $_POST['keep'] ) ? filter_var( wp_unslash( $_POST['keep'] ), FILTER_VALIDATE_INT ) : false;
        if ( ! in_array( $frequency, array( 'off', 'hourly', 'daily', 'weekly', 'monthly' ), true ) ||
             $keep === false || $keep < 1 || $keep > 100 ) {
            wp_die( 'Invalid schedule configuration.', '', array( 'response' => 400 ) );
        }
        $before_updates = isset( $_POST['before_updates'] ) && $_POST['before_updates'] === '1';
        $upload_offsite = isset( $_POST['upload_offsite'] ) && $_POST['upload_offsite'] === '1';
        $notify_alerts = isset( $_POST['notify_alerts'] ) && $_POST['notify_alerts'] === '1';
        $notification_email = isset( $_POST['notification_email'] ) && is_string( $_POST['notification_email'] )
            ? sanitize_email( wp_unslash( $_POST['notification_email'] ) ) : get_option( 'admin_email' );
        if ( ! is_email( $notification_email ) ) {
            wp_die( 'A valid alert email address is required.', '', array( 'response' => 400 ) );
        }
        $webhook_url = isset( $_POST['webhook_url'] ) && is_string( $_POST['webhook_url'] )
            ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ), array( 'https' ) ) : '';
        if ( $webhook_url !== '' && ( ! self::valid_webhook_url( $webhook_url ) || wp_http_validate_url( $webhook_url ) === false ) ) {
            wp_die( 'Webhook URL must be a valid public HTTPS URL.', '', array( 'response' => 400 ) );
        }
        $existing = get_option( self::OPTION, array() );
        $existing_secret = is_array( $existing ) && isset( $existing['webhook_secret'] ) &&
            is_string( $existing['webhook_secret'] ) ? $existing['webhook_secret'] : '';
        $webhook_secret = isset( $_POST['webhook_secret'] ) && is_string( $_POST['webhook_secret'] )
            ? trim( wp_unslash( $_POST['webhook_secret'] ) ) : '';
        if ( $webhook_secret === '' ) {
            $webhook_secret = $existing_secret;
        }
        if ( $webhook_url !== '' && ( strlen( $webhook_secret ) < 32 || strlen( $webhook_secret ) > 128 ) ) {
            wp_die( 'Webhook signing secret must contain 32 to 128 characters.', '', array( 'response' => 400 ) );
        }
        update_option( self::OPTION, array(
            'frequency' => $frequency,
            'keep' => $keep,
            'before_updates' => $before_updates,
            'upload_offsite' => $upload_offsite,
            'notify_alerts' => $notify_alerts,
            'notification_email' => $notification_email,
            'webhook_url' => $webhook_url,
            'webhook_secret' => $webhook_secret,
        ), false );
        wp_clear_scheduled_hook( self::HOOK );
        self::sync();
        wp_safe_redirect( admin_url( 'admin.php?page=trasloco_backups&schedule_saved=1' ) );
        exit;
    }

    public static function enqueue() {
        $settings = self::settings();
        if ( $settings['frequency'] === 'off' ) {
            return false;
        }
        if ( $settings['frequency'] === 'monthly' ) {
            $bucket = wp_date( 'Y-m' );
            // One-shot WP-Cron events are removed before calling this hook.
            self::sync();
        } else {
            $seconds = array( 'hourly' => HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS );
            $bucket = (int) floor( time() / $seconds[ $settings['frequency'] ] );
        }
        $key = hash( 'sha256', 'scheduled-backup|' . $settings['frequency'] . '|' . $bucket );
        $id = Trasloco_Job_Store::create( 'backup', array(
            'retention' => $settings['keep'],
            'upload_offsite' => $settings['upload_offsite'],
        ), $key );
        if ( is_wp_error( $id ) || ! $id ) {
            return false;
        }
        Trasloco_Job_Worker::schedule( $id );
        return $id;
    }
}
