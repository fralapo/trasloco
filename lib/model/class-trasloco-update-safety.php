<?php
/** Opt-in pre-upgrade snapshot. A failed snapshot blocks the update. */
class Trasloco_Update_Safety {
    private static $in_progress = false;
    private static $completed_this_request = false;

    public static function register() {
        add_filter( 'upgrader_pre_install', array( __CLASS__, 'before_install' ), 1, 2 );
    }

    public static function before_install( $response, $context ) {
        if ( is_wp_error( $response ) || self::$in_progress || self::$completed_this_request ) {
            return $response;
        }
        $settings = Trasloco_Scheduled_Backups::settings();
        if ( empty( $settings['before_updates'] ) || ! is_array( $context ) ||
            ! isset( $context['action'], $context['type'] ) ||
            $context['action'] !== 'update' ||
            ! in_array( $context['type'], array( 'core', 'plugin', 'theme' ), true ) ) {
            return $response;
        }
        self::$in_progress = true;
        try {
            $id = Trasloco_Job_Store::create( 'backup', array( 'recovery_point' => true ),
                hash( 'sha256', 'pre-update|' . wp_generate_password( 24, false, false ) ) );
            if ( is_wp_error( $id ) || ! $id ) {
                return new WP_Error( 'trasloco_update_checkpoint_failed', 'Update stopped: a safety backup could not be queued.' );
            }
            $result = Trasloco_Job_Worker::run( $id );
            if ( $result['status'] !== 'completed' ) {
                return new WP_Error( 'trasloco_update_checkpoint_failed', 'Update stopped: a verified safety backup could not be created.' );
            }
            self::$completed_this_request = true;
            return $response;
        } catch ( Throwable $e ) {
            return new WP_Error( 'trasloco_update_checkpoint_failed', 'Update stopped: the safety backup failed.' );
        } finally {
            self::$in_progress = false;
        }
    }
}
