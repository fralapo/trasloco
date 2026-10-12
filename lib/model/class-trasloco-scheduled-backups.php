<?php
/** WP-Cron configuration and durable job creation for scheduled backups. */
class Trasloco_Scheduled_Backups {
    const OPTION = 'trasloco_automatic_backup_settings';
    const HOOK = 'trasloco_scheduled_backup_tick';

    public static function register() {
        add_action( 'init', array( __CLASS__, 'sync' ), 30 );
        add_action( 'admin_post_trasloco_save_schedule', array( __CLASS__, 'save' ) );
        add_action( self::HOOK, array( __CLASS__, 'enqueue' ) );
    }

    public static function settings() {
        $value = get_option( self::OPTION, array() );
        $value = is_array( $value ) ? $value : array();
        $frequency = isset( $value['frequency'] ) ? $value['frequency'] : 'off';
        $keep = isset( $value['keep'] ) ? (int) $value['keep'] : 7;
        if ( ! in_array( $frequency, array( 'off', 'hourly', 'daily', 'weekly' ), true ) ) {
            $frequency = 'off';
        }
        return array( 'frequency' => $frequency, 'keep' => max( 1, min( 100, $keep ) ), 'before_updates' => ! empty( $value['before_updates'] ) );
    }

    public static function sync() {
        $frequency = self::settings()['frequency'];
        $next = wp_next_scheduled( self::HOOK );
        if ( $frequency === 'off' ) {
            if ( $next !== false ) {
                wp_clear_scheduled_hook( self::HOOK );
            }
            return;
        }
        if ( $next === false ) {
            $seconds = array( 'hourly' => HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS );
            wp_schedule_event( time() + $seconds[ $frequency ], $frequency, self::HOOK );
        }
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not permitted.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'trasloco_save_schedule' );
        $frequency = isset( $_POST['frequency'] ) && is_string( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'off';
        $keep = isset( $_POST['keep'] ) ? filter_var( wp_unslash( $_POST['keep'] ), FILTER_VALIDATE_INT ) : false;
        if ( ! in_array( $frequency, array( 'off', 'hourly', 'daily', 'weekly' ), true ) ||
             $keep === false || $keep < 1 || $keep > 100 ) {
            wp_die( 'Invalid schedule configuration.', '', array( 'response' => 400 ) );
        }
        $before_updates = isset( $_POST['before_updates'] ) && $_POST['before_updates'] === '1';
        update_option( self::OPTION, array( 'frequency' => $frequency, 'keep' => $keep, 'before_updates' => $before_updates ), false );
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
        $seconds = array( 'hourly' => HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS );
        $bucket = (int) floor( time() / $seconds[ $settings['frequency'] ] );
        $key = hash( 'sha256', 'scheduled-backup|' . $settings['frequency'] . '|' . $bucket );
        $id = Trasloco_Job_Store::create( 'backup', array( 'retention' => $settings['keep'] ), $key );
        if ( is_wp_error( $id ) || ! $id ) {
            return false;
        }
        Trasloco_Job_Worker::schedule( $id );
        return $id;
    }
}
