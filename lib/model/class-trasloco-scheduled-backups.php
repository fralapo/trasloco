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
        add_action( self::NOTIFICATION_HOOK, array( __CLASS__, 'retry_notification' ), 10, 2 );
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
        $email = $settings['notification_email'];
        if ( ! $settings['notify_alerts'] || ! is_email( $email ) ) {
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
        if ( wp_mail( $email, $messages[ $event ][0], $messages[ $event ][1] ) ) {
            return true;
        }
        if ( $attempt < 3 ) {
            $delays = array( 60, 300, 900 );
            wp_schedule_single_event( time() + $delays[ $attempt ], self::NOTIFICATION_HOOK, array( $event, $attempt + 1 ) );
        }
        return false;
    }

    /** Retry only known, credential-free alert types and bounded attempts. */
    public static function retry_notification( $event, $attempt ) {
        if ( ! is_string( $event ) || ! is_int( $attempt ) ) {
            return false;
        }
        return self::send_alert( $event, $attempt );
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
        update_option( self::OPTION, array(
            'frequency' => $frequency,
            'keep' => $keep,
            'before_updates' => $before_updates,
            'upload_offsite' => $upload_offsite,
            'notify_alerts' => $notify_alerts,
            'notification_email' => $notification_email,
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
