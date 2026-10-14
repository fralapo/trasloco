<?php
/** Small, bounded audit trail for administrator-triggered REST mutations. */
class Trasloco_Audit_Log {
    const SCHEMA = 1;
    const RETAIN = 1000;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'trasloco_audit';
    }

    public static function maybe_install() {
        if ( (int) get_option( 'trasloco_audit_schema_version', 0 ) >= self::SCHEMA ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(48) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id),
            KEY object_id (object_id)
        ) {$charset};" );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            update_option( 'trasloco_audit_schema_version', self::SCHEMA, false );
        }
    }

    /** Store only the actor and action; never persist payloads or secrets. */
    public static function record( $action, $object_id, $actor_id = null ) {
        global $wpdb;
        $allowed = array( 'backup.queued', 'verification.queued', 'job.retried', 'job.cancelled' );
        if ( ! in_array( $action, $allowed, true ) || ! is_numeric( $object_id ) || (int) $object_id < 1 ) {
            return false;
        }
        if ( $actor_id === null ) {
            $actor_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
        }
        if ( ! is_numeric( $actor_id ) || (int) $actor_id < 0 ) {
            return false;
        }
        self::maybe_install();
        $table = self::table();
        $inserted = $wpdb->insert( $table, array(
            'actor_id' => (int) $actor_id,
            'action' => $action,
            'object_id' => (int) $object_id,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ), array( '%d', '%s', '%d', '%s' ) );
        if ( $inserted !== 1 ) {
            return false;
        }
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id <= %d', max( 0, (int) $wpdb->insert_id - self::RETAIN ) ) );
        return true;
    }

    /** Bounded actor/action history for the admin-only REST route. */
    public static function recent( $limit = 100 ) {
        global $wpdb;
        self::maybe_install();
        $limit = max( 1, min( 100, (int) $limit ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT actor_id, action, object_id, created_at FROM ' . self::table() .
            ' ORDER BY id DESC LIMIT %d', $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }
}
