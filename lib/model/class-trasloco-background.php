<?php
/**
 * Persistent, resumable full-backup jobs using the existing export stages.
 * WP-Cron is traffic-dependent; a system cron running wp-cron.php is advised.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Background {
    const CONF = 'trasloco_2_schedule';
    const ACTIVE = 'trasloco_2_active_job';
    const LAST = 'trasloco_2_last_job';
    const MUTEX = 'trasloco_2_job_mutex';
    const TICK = 'trasloco_2_backup_tick';
    const PERIODIC = 'trasloco_2_backup_periodic';

    public static function stages() {
        return array(
            'Trasloco_Export_Init',
            'Trasloco_Export_Archive',
            'Trasloco_Export_Config',
            'Trasloco_Export_Config_File',
            'Trasloco_Export_Enumerate',
            'Trasloco_Export_Content',
            'Trasloco_Export_Database',
            'Trasloco_Export_Database_File',
            'Trasloco_Export_Download',
        );
    }

    public static function config() {
        $config = get_option( self::CONF, array() );
        return wp_parse_args( is_array( $config ) ? $config : array(), array(
            'enabled' => false,
            'interval' => 'daily',
            'keep' => 7,
            'notify' => true,
        ) );
    }

    public static function save( $config ) {
        if ( ! is_array( $config ) ) {
            return false;
        }
        $valid = array( 'hourly', 'daily', 'weekly' );
        $interval = isset( $config['interval'] ) ? $config['interval'] : 'daily';
        if ( ! in_array( $interval, $valid, true ) ) {
            $interval = 'daily';
        }
        $clean = array(
            'enabled' => ! empty( $config['enabled'] ),
            'interval' => $interval,
            'keep' => min( 365, max( 1, (int) ( isset( $config['keep'] ) ? $config['keep'] : 7 ) ) ),
            'notify' => ! empty( $config['notify'] ),
        );
        update_option( self::CONF, $clean, false );
        wp_clear_scheduled_hook( self::PERIODIC );
        if ( $clean['enabled'] && ! is_multisite() ) {
            return (bool) wp_schedule_event( time() + MINUTE_IN_SECONDS, $clean['interval'], self::PERIODIC );
        }
        return true;
    }

    public static function periodic() {
        $settings = self::config();
        if ( $settings['enabled'] ) {
            self::start();
        }
    }

    /** Only one active backup job per WordPress installation. */
    public static function start() {
        if ( is_multisite() ) {
            return false;
        }
        $job = array(
            'id' => wp_generate_uuid4(),
            'state' => 'queued',
            'index' => 0,
            'params' => array( 'options' => array( 'no_cache' => true ) ),
            'created_at' => time(),
            'updated_at' => time(),
        );
        if ( ! add_option( self::ACTIVE, $job, '', false ) ) {
            return false;
        }
        self::enqueue();
        return $job['id'];
    }

    private static function enqueue() {
        if ( ! wp_next_scheduled( self::TICK ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::TICK );
        }
    }

    private static function notify_failure( $message ) {
        $settings = self::config();
        if ( $settings['notify'] ) {
            wp_mail( get_option( 'admin_email' ), 'Trasloco backup failed', 'The scheduled backup stopped: ' . $message );
        }
    }

    private static function prune() {
        $settings = self::config();
        $backups = new Trasloco_Backups();
        foreach ( array_slice( $backups->get_files(), $settings['keep'] ) as $old ) {
            $backups->delete_file( $old['filename'] );
        }
    }

    /** One bounded export stage per invocation, with durable checkpoints. */
    public static function tick() {
        $job = get_option( self::ACTIVE, false );
        if ( ! is_array( $job ) || empty( $job['id'] ) ) {
            return;
        }
        $token = wp_generate_password( 32, false );
        if ( ! add_option( self::MUTEX, array( 'token' => $token, 'since' => time() ), '', false ) ) {
            $stale = get_option( self::MUTEX );
            if ( is_array( $stale ) && isset( $stale['since'] ) && (int) $stale['since'] < time() - 600 ) {
                delete_option( self::MUTEX );
            }
            self::enqueue();
            return;
        }
        try {
            $stages = self::stages();
            $index = isset( $job['index'] ) ? (int) $job['index'] : 0;
            if ( $index < 0 || $index >= count( $stages ) ) {
                throw new RuntimeException( 'Unknown backup stage.' );
            }
            $handler = $stages[ $index ];
            $params = isset( $job['params'] ) && is_array( $job['params'] ) ? $job['params'] : array();
            // Each long-running phase uses the existing 10-second completion
            // limit and returns a cursor when another invocation is required.
            $params = call_user_func( array( $handler, 'execute' ), $params );
            if ( ! is_array( $params ) ) {
                throw new RuntimeException( 'Backup stage returned an invalid result.' );
            }
            $incomplete = isset( $params['completed'] ) && $params['completed'] === false;
            unset( $params['completed'] );
            if ( ! $incomplete ) {
                $index++;
            }
            if ( $index === count( $stages ) ) {
                if ( empty( $params['archive'] ) ) {
                    throw new RuntimeException( 'The export did not produce an archive.' );
                }
                $verification = Trasloco_Manifest::inspect( $params['archive'] );
                if ( $verification['status'] !== 'verified' ) {
                    throw new RuntimeException( 'The new backup did not pass integrity verification.' );
                }
                $summary = array(
                    'state' => 'completed',
                    'id' => $job['id'],
                    'archive' => $params['archive'],
                    'updated_at' => time(),
                    'bytes' => $verification['bytes'],
                );
                update_option( self::LAST, $summary, false );
                delete_option( self::ACTIVE );
                if ( ! empty( $params['storage'] ) ) {
                    Trasloco_Directory::delete( trasloco_storage_path( $params ) );
                }
                self::prune();
            } else {
                $job['index'] = $index;
                $job['state'] = 'running';
                $job['params'] = $params;
                $job['updated_at'] = time();
                update_option( self::ACTIVE, $job, false );
                self::enqueue();
            }
        } catch ( Throwable $e ) {
            // Do not claim success when the destination or storage failed.
            $summary = array(
                'state' => 'failed',
                'id' => $job['id'],
                'updated_at' => time(),
                'error' => substr( $e->getMessage(), 0, 500 ),
            );
            update_option( self::LAST, $summary, false );
            delete_option( self::ACTIVE );
            self::notify_failure( $summary['error'] );
        } finally {
            $lock = get_option( self::MUTEX );
            if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( $token, $lock['token'] ) ) {
                delete_option( self::MUTEX );
            }
        }
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        Trasloco_Template::render( 'main/schedule', array(
            'settings' => self::config(),
            'active' => get_option( self::ACTIVE, false ),
            'last' => get_option( self::LAST, false ),
        ) );
    }

    public static function save_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'trasloco_schedule_save' );
        $post = isset( $_POST['schedule'] ) && is_array( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : array();
        self::save( $post );
        wp_safe_redirect( admin_url( 'admin.php?page=trasloco_schedule&updated=1' ) );
        exit;
    }

    public static function start_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'trasloco_schedule_start' );
        self::start();
        wp_safe_redirect( admin_url( 'admin.php?page=trasloco_schedule' ) );
        exit;
    }
}
