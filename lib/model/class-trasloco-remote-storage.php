<?php
/**
 * Pluggable remote archive storage. Backends operate on bounded streams and
 * accept only safe archive object names. No credentials are stored in options.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
interface Trasloco_Storage_Backend {
    public function upload( $source, $object );
    public function download( $object, $destination );
}

class Trasloco_Storage {
    public static function validate_object( $object ) {
        if ( ! is_string( $object ) ||
            ! preg_match( '/^[A-Za-z0-9_.-]+\\.wpress(\\.enc|\\.manifest\\.json)?$/D', $object ) ) {
            throw new RuntimeException( 'Invalid remote backup object name.' );
        }
        return $object;
    }

    public static function provider() {
        $driver = defined( 'TRASLOCO_REMOTE_DRIVER' ) ? TRASLOCO_REMOTE_DRIVER : '';
        if ( $driver === 'local' ) {
            if ( ! defined( 'TRASLOCO_REMOTE_DIRECTORY' ) ) {
                throw new RuntimeException( 'TRASLOCO_REMOTE_DIRECTORY is not configured.' );
            }
            return new Trasloco_Storage_Local( TRASLOCO_REMOTE_DIRECTORY );
        }
        if ( $driver === 's3' ) {
            return new Trasloco_Storage_S3();
        }
        if ( $driver === 'webdav' ) {
            return new Trasloco_Storage_Webdav();
        }
        throw new RuntimeException( 'Configure TRASLOCO_REMOTE_DRIVER (local, s3, webdav).' );
    }
}

class Trasloco_Storage_Local implements Trasloco_Storage_Backend {
    private $directory;

    public function __construct( $directory ) {
        if ( ! is_string( $directory ) || $directory === '' || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
            throw new RuntimeException( 'Private local storage directory is missing or not writable.' );
        }
        $this->directory = realpath( $directory );
    }

    private function object_path( $key ) {
        return $this->directory . DIRECTORY_SEPARATOR . Trasloco_Storage::validate_object( $key );
    }

    public function upload( $source, $object ) {
        $target = $this->object_path( $object );
        if ( ! is_file( $source ) || is_link( $source ) || is_link( $target ) ) {
            throw new RuntimeException( 'Unsafe source or destination backup.' );
        }
        $input = @fopen( $source, 'rb' );
        $temp = $target . '.upload-' . bin2hex( random_bytes( 8 ) );
        $output = @fopen( $temp, 'xb' );
        if ( ! $input || ! $output ) {
            if ( $input ) { fclose( $input ); }
            if ( $output ) { fclose( $output ); }
            throw new RuntimeException( 'Failed opening private storage streams.' );
        }
        try {
            $total = stream_copy_to_stream( $input, $output );
            if ( $total === false || $total !== filesize( $source ) || ! fflush( $output ) ) {
                throw new RuntimeException( 'Incomplete private storage upload.' );
            }
            fclose( $output );
            $output = null;
            if ( is_link( $target ) || ! @rename( $temp, $target ) ) {
                throw new RuntimeException( 'Unable to finalize private storage upload.' );
            }
        } finally {
            fclose( $input );
            if ( is_resource( $output ) ) { fclose( $output ); }
            if ( is_file( $temp ) ) { unlink( $temp ); }
        }
    }

    public function download( $object, $destination ) {
        $source = $this->object_path( $object );
        if ( ! is_file( $source ) || is_link( $source ) || file_exists( $destination ) ) {
            throw new RuntimeException( 'Missing remote backup or occupied destination.' );
        }
        $in = fopen( $source, 'rb' );
        $out = fopen( $destination, 'xb' );
        if ( ! $in || ! $out ) {
            if ( $in ) { fclose( $in ); }
            if ( $out ) { fclose( $out ); }
            throw new RuntimeException( 'Unable to open download streams.' );
        }
        try {
            $bytes = stream_copy_to_stream( $in, $out );
            if ( $bytes === false || $bytes !== filesize( $source ) || ! fflush( $out ) ) {
                throw new RuntimeException( 'Incomplete remote download.' );
            }
        } finally {
            fclose( $in );
            fclose( $out );
        }
    }
}

class Trasloco_Storage_S3 implements Trasloco_Storage_Backend {
    private $client;
    private $bucket;
    private $prefix;

    public function __construct() {
        if ( ! class_exists( 'Aws\\S3\\S3Client' ) && is_readable( TRASLOCO_PATH . '/vendor/autoload.php' ) ) {
            require_once TRASLOCO_PATH . '/vendor/autoload.php';
        }
        if ( ! class_exists( 'Aws\\S3\\S3Client' ) ) {
            throw new RuntimeException( 'AWS SDK for PHP v3 is required; install Composer production dependencies.' );
        }
        if ( ! defined( 'TRASLOCO_S3_BUCKET' ) || ! TRASLOCO_S3_BUCKET ) {
            throw new RuntimeException( 'S3 bucket not configured.' );
        }
        $this->bucket = TRASLOCO_S3_BUCKET;
        $this->prefix = defined( 'TRASLOCO_S3_PREFIX' ) ? trim( TRASLOCO_S3_PREFIX, '/' ) . '/' : '';
        if ( $this->prefix !== '' && ! preg_match( '/^[A-Za-z0-9._\\/-]+$/D', $this->prefix ) ) {
            throw new RuntimeException( 'Unsafe S3 prefix.' );
        }
        $config = array(
            'version' => 'latest',
            'region' => defined( 'TRASLOCO_S3_REGION' ) ? TRASLOCO_S3_REGION : 'us-east-1',
        );
        if ( defined( 'TRASLOCO_S3_ENDPOINT' ) && TRASLOCO_S3_ENDPOINT ) {
            if ( parse_url( TRASLOCO_S3_ENDPOINT, PHP_URL_SCHEME ) !== 'https' ) {
                throw new RuntimeException( 'S3-compatible endpoint must use HTTPS.' );
            }
            $config['endpoint'] = TRASLOCO_S3_ENDPOINT;
            $config['use_path_style_endpoint'] = defined( 'TRASLOCO_S3_PATH_STYLE' ) && TRASLOCO_S3_PATH_STYLE;
        }
        if ( defined( 'TRASLOCO_S3_KEY' ) && defined( 'TRASLOCO_S3_SECRET' ) ) {
            $config['credentials'] = array( 'key' => TRASLOCO_S3_KEY, 'secret' => TRASLOCO_S3_SECRET );
        }
        $this->client = new \\Aws\\S3\\S3Client( $config );
    }

    public function upload( $source, $object ) {
        if ( ! is_file( $source ) || is_link( $source ) ) {
            throw new RuntimeException( 'Invalid source backup.' );
        }
        $key = $this->prefix . Trasloco_Storage::validate_object( $object );
        $uploader = new \\Aws\\S3\\ObjectUploader( $this->client, $source, array(
            'bucket' => $this->bucket, 'key' => $key,
        ) );
        $uploader->upload();
    }

    public function download( $object, $destination ) {
        if ( file_exists( $destination ) || is_link( $destination ) ) {
            throw new RuntimeException( 'Download destination already exists.' );
        }
        $this->client->getObject( array(
            'Bucket' => $this->bucket,
            'Key' => $this->prefix . Trasloco_Storage::validate_object( $object ),
            'SaveAs' => $destination,
        ) );
    }
}

class Trasloco_Storage_Webdav implements Trasloco_Storage_Backend {
    private $base;
    private $user;
    private $password;

    public function __construct() {
        if ( ! function_exists( 'curl_init' ) || ! defined( 'TRASLOCO_WEBDAV_URL' ) ||
             ! defined( 'TRASLOCO_WEBDAV_USER' ) || ! defined( 'TRASLOCO_WEBDAV_PASSWORD' ) ) {
            throw new RuntimeException( 'WebDAV requires PHP cURL and server-side URL/credentials.' );
        }
        $url = TRASLOCO_WEBDAV_URL;
        if ( parse_url( $url, PHP_URL_SCHEME ) !== 'https' || ! wp_http_validate_url( $url ) ) {
            throw new RuntimeException( 'WebDAV requires an externally validated HTTPS URL.' );
        }
        $this->base = rtrim( $url, '/' ) . '/';
        $this->user = TRASLOCO_WEBDAV_USER;
        $this->password = TRASLOCO_WEBDAV_PASSWORD;
    }

    private function transfer( $object, $file, $upload ) {
        $key = Trasloco_Storage::validate_object( $object );
        $stream = @fopen( $file, $upload ? 'rb' : 'xb' );
        if ( $stream === false ) {
            throw new RuntimeException( 'Unable to open the WebDAV transfer stream.' );
        }
        $curl = curl_init( $this->base . rawurlencode( $key ) );
        curl_setopt_array( $curl, array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->user . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ) );
        if ( $upload ) {
            curl_setopt( $curl, CURLOPT_UPLOAD, true );
            curl_setopt( $curl, CURLOPT_INFILE, $stream );
            curl_setopt( $curl, CURLOPT_INFILESIZE_LARGE, filesize( $file ) );
        } else {
            curl_setopt( $curl, CURLOPT_FILE, $stream );
        }
        try {
            $ok = curl_exec( $curl );
            $status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
            if ( $ok === false || $status < 200 || $status >= 300 ) {
                throw new RuntimeException( 'WebDAV transfer failed or was rejected by remote storage.' );
            }
        } finally {
            curl_close( $curl );
            fclose( $stream );
        }
    }

    public function upload( $source, $object ) {
        if ( ! is_file( $source ) || is_link( $source ) ) {
            throw new RuntimeException( 'Invalid WebDAV source.' );
        }
        $this->transfer( $object, $source, true );
    }

    public function download( $object, $destination ) {
        if ( file_exists( $destination ) || is_link( $destination ) ) {
            throw new RuntimeException( 'WebDAV download destination exists.' );
        }
        $this->transfer( $object, $destination, false );
    }
}
