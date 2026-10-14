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
        if ( $driver === 's3' ) {
            if ( ! defined( 'TRASLOCO_S3_BUCKET' ) || ! defined( 'TRASLOCO_S3_REGION' ) ) {
                throw new RuntimeException( 'S3 bucket and signing region must be configured in wp-config.php.' );
            }
            $config = array(
                'bucket' => TRASLOCO_S3_BUCKET,
                'region' => TRASLOCO_S3_REGION,
                'prefix' => defined( 'TRASLOCO_S3_PREFIX' ) ? TRASLOCO_S3_PREFIX : '',
            );
            if ( defined( 'TRASLOCO_S3_ENDPOINT' ) && TRASLOCO_S3_ENDPOINT !== '' ) {
                $config['endpoint'] = TRASLOCO_S3_ENDPOINT;
                $config['path_style'] = defined( 'TRASLOCO_S3_PATH_STYLE' ) && TRASLOCO_S3_PATH_STYLE;
            }
            $has_key = defined( 'TRASLOCO_S3_ACCESS_KEY' ) || defined( 'TRASLOCO_S3_KEY' );
            $has_secret = defined( 'TRASLOCO_S3_SECRET_KEY' ) || defined( 'TRASLOCO_S3_SECRET' );
            if ( $has_key !== $has_secret ) {
                throw new RuntimeException( 'Configure both S3 access key and secret, or use the server credential chain.' );
            }
            if ( $has_key ) {
                $config['credentials'] = array(
                    'key' => defined( 'TRASLOCO_S3_ACCESS_KEY' ) ? TRASLOCO_S3_ACCESS_KEY : TRASLOCO_S3_KEY,
                    'secret' => defined( 'TRASLOCO_S3_SECRET_KEY' ) ? TRASLOCO_S3_SECRET_KEY : TRASLOCO_S3_SECRET,
                );
                if ( defined( 'TRASLOCO_S3_SESSION_TOKEN' ) ) {
                    $config['credentials']['token'] = TRASLOCO_S3_SESSION_TOKEN;
                }
            }
            return new Trasloco_S3_Storage( $config );
        }
        throw new RuntimeException( 'Configure TRASLOCO_REMOTE_DRIVER as local, webdav or s3 in wp-config.php.' );
    }
}
