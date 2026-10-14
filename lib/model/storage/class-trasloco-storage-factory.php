<?php
/** Build a remote adapter exclusively from private wp-config.php settings. */
class Trasloco_Storage_Factory {
    public static function configured() {
        $driver = defined( 'TRASLOCO_REMOTE_DRIVER' ) ? TRASLOCO_REMOTE_DRIVER : '';
        if ( $driver === 'local' ) {
            if ( ! defined( 'TRASLOCO_REMOTE_DIRECTORY' ) || ! is_string( TRASLOCO_REMOTE_DIRECTORY ) ) {
                throw new RuntimeException( 'TRASLOCO_REMOTE_DIRECTORY is not configured.' );
            }
            return new Trasloco_Local_Storage( TRASLOCO_REMOTE_DIRECTORY );
        }
        if ( $driver === 'webdav' ) {
            if ( ! defined( 'TRASLOCO_WEBDAV_URL' ) || ! defined( 'TRASLOCO_WEBDAV_USER' ) ||
                ! defined( 'TRASLOCO_WEBDAV_PASSWORD' ) ) {
                throw new RuntimeException( 'WebDAV URL and credentials must be configured in wp-config.php.' );
            }
            return new Trasloco_WebDAV_Storage(
                TRASLOCO_WEBDAV_URL,
                TRASLOCO_WEBDAV_USER,
                TRASLOCO_WEBDAV_PASSWORD
            );
        }
        throw new RuntimeException( 'Configure TRASLOCO_REMOTE_DRIVER as local or webdav in wp-config.php.' );
    }
}
