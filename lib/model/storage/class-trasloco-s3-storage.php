<?php
/**
 * S3-compatible object storage. The AWS SDK is an optional runtime dependency.
 * Objects are private and immutable: writes use conditional creation.
 */
class Trasloco_S3_Storage implements Trasloco_Storage_Adapter {
    const MAX_INVENTORY_ITEMS = 10000;
    const MEMORY_STREAM_BYTES = 2097152;
    const MULTIPART_THRESHOLD_BYTES = 16777216;
    const MIN_MULTIPART_PART_BYTES = 8388608;
    const MAX_OBJECT_BYTES = 5497558138880; // S3 maximum: 5 TiB.
    const MAX_MULTIPART_PART_BYTES = 5368709120; // S3 maximum: 5 GiB.

    private $client;
    private $bucket;
    private $prefix;

    public function __construct( $config, $client = null ) {
        if ( ! is_array( $config ) || empty( $config['bucket'] ) || ! is_string( $config['bucket'] ) ||
            ! preg_match( '/^(?=.{3,63}$)(?![0-9]+(?:\.[0-9]+){3}$)(?!.*\.\.)(?!.*\.-)(?!.*-\.)(?!xn--)[a-z0-9][a-z0-9.-]*[a-z0-9]$/D', $config['bucket'] ) ) {
            throw new InvalidArgumentException( 'A valid S3 bucket name is required.' );
        }
        $region = isset( $config['region'] ) ? $config['region'] : '';
        if ( ! is_string( $region ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,62}$/D', $region ) ) {
            throw new InvalidArgumentException( 'A valid S3 signing region is required.' );
        }
        $prefix = isset( $config['prefix'] ) ? $config['prefix'] : '';
        if ( ! is_string( $prefix ) || ( $prefix !== '' &&
            ( ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9._/-]{0,399}$#D', $prefix ) ||
              strpos( $prefix, '..' ) !== false || strpos( $prefix, '//' ) !== false ||
              preg_match( '#(?:^|/)\.(?:/|$)#', $prefix ) ) ) ) {
            throw new InvalidArgumentException( 'Unsafe S3 object prefix.' );
        }
        $this->bucket = $config['bucket'];
        $this->prefix = trim( $prefix, '/' );
        if ( $this->prefix !== '' ) {
            $this->prefix .= '/';
        }

        $sdk_config = array(
            'version' => '2006-03-01',
            'region' => $region,
            'signature_version' => 'v4',
            'http' => array(
                'connect_timeout' => 15,
                'timeout' => 900,
                'allow_redirects' => false,
                'verify' => true,
                'proxy' => '',
            ),
        );
        if ( isset( $config['endpoint'] ) && $config['endpoint'] !== '' ) {
            $endpoint = $config['endpoint'];
            $parts = is_string( $endpoint ) ? parse_url( $endpoint ) : false;
            if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || strtolower( $parts['scheme'] ) !== 'https' ||
                empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
                isset( $parts['query'] ) || isset( $parts['fragment'] ) || strpos( $endpoint, '\\' ) !== false ||
                preg_match( '/[\x00-\x20\x7f]/', $endpoint ) ) {
                throw new InvalidArgumentException( 'S3-compatible endpoints must be safe HTTPS URLs without embedded credentials.' );
            }
            $sdk_config['endpoint'] = $endpoint;
            $sdk_config['use_path_style_endpoint'] = ! empty( $config['path_style'] );
        }
        if ( isset( $config['credentials'] ) ) {
            if ( ! is_array( $config['credentials'] ) || empty( $config['credentials']['key'] ) ||
                empty( $config['credentials']['secret'] ) ) {
                throw new InvalidArgumentException( 'S3 access key and secret must be configured together.' );
            }
            $sdk_config['credentials'] = array(
                'key' => $config['credentials']['key'],
                'secret' => $config['credentials']['secret'],
            );
            if ( isset( $config['credentials']['token'] ) && $config['credentials']['token'] !== '' ) {
                $sdk_config['credentials']['token'] = $config['credentials']['token'];
            }
        }
        if ( $client !== null ) {
            $this->client = $client;
            return;
        }
        if ( PHP_VERSION_ID < 80100 ) {
            throw new RuntimeException( 'S3 storage requires PHP 8.1 or newer; other Trasloco storage drivers keep their existing PHP support.' );
        }
        $autoload = dirname( __DIR__, 3 ) . '/vendor-s3/autoload.php';
        if ( ! class_exists( 'Aws\\S3\\S3Client' ) && is_readable( $autoload ) ) {
            require_once $autoload;
        }
        if ( ! class_exists( 'Aws\\S3\\S3Client' ) ) {
            throw new RuntimeException( 'S3 storage requires the optional AWS SDK runtime; see docs/OFFSITE_S3.md.' );
        }
        $client_class = 'Aws\\S3\\S3Client';
        $this->client = new $client_class( $sdk_config );
    }

    private function object_key( $key ) {
        $valid = is_string( $key ) && strlen( $key ) <= 220 && strpos( $key, '..' ) === false && (
            preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc(?:\.manifest\.json)?|\.manifest\.json|\.parts\.json)?$/D', $key ) ||
            preg_match( '/^trp-[a-f0-9]{64}-[0-9]{6}\.part$/D', $key ) ||
            preg_match( '/^trs-[a-f0-9]{24}\.part$/D', $key )
        );
        if ( ! $valid ) {
            throw new InvalidArgumentException( 'Invalid S3 storage key.' );
        }
        return $this->prefix . $key;
    }

    private function s3_error( $error, $operation ) {
        $code = method_exists( $error, 'getAwsErrorCode' ) ? $error->getAwsErrorCode() : '';
        $status = method_exists( $error, 'getStatusCode' ) ? (int) $error->getStatusCode() : 0;
        if ( in_array( $status, array( 408, 425, 429, 500, 502, 503, 504 ), true ) ||
            in_array( $code, array( 'RequestTimeout', 'RequestTimeoutException', 'SlowDown', 'InternalError', 'ServiceUnavailable' ), true ) ) {
            throw new Trasloco_Storage_Transient_Exception( 'S3 storage returned a temporary error during ' . $operation . '.' );
        }
        if ( $code === 'PreconditionFailed' || $status === 412 ) {
            throw new RuntimeException( 'S3 object already exists; replacement is refused.' );
        }
        throw new RuntimeException( 'S3 storage rejected the ' . $operation . ' request.' );
    }

    private function copy_response_body( $body, $expected_bytes = null, $max_bytes = null ) {
        if ( ! is_object( $body ) || ! method_exists( $body, 'read' ) ) {
            throw new RuntimeException( 'S3 returned an invalid object stream.' );
        }
        $output = fopen( 'php://temp/maxmemory:' . self::MEMORY_STREAM_BYTES, 'w+b' );
        if ( $output === false ) {
            throw new RuntimeException( 'Unable to create a private S3 download stream.' );
        }
        $copied = 0;
        try {
            while ( ! $body->eof() ) {
                $chunk = $body->read( 1048576 );
                if ( ! is_string( $chunk ) || ( $chunk === '' && ! $body->eof() ) ) {
                    throw new RuntimeException( 'S3 object stream ended unexpectedly.' );
                }
                if ( $chunk === '' ) {
                    continue;
                }
                $length = strlen( $chunk );
                if ( ( $max_bytes !== null && $copied + $length > $max_bytes ) ||
                    ( $expected_bytes !== null && $copied + $length > $expected_bytes ) ) {
                    throw new RuntimeException( 'S3 returned more bytes than requested.' );
                }
                $offset = 0;
                while ( $offset < $length ) {
                    $written = fwrite( $output, substr( $chunk, $offset ) );
                    if ( $written === false || $written === 0 ) {
                        throw new RuntimeException( 'Unable to write the private S3 download stream.' );
                    }
                    $offset += $written;
                }
                $copied += $length;
            }
            if ( ( $expected_bytes !== null && $copied !== $expected_bytes ) || ! rewind( $output ) ) {
                throw new RuntimeException( 'S3 did not return the exact requested bytes.' );
            }
            return $output;
        } catch ( Throwable $error ) {
            fclose( $output );
            throw $error;
        }
    }

    public function put_stream( $key, $handle ) {
        $key = $this->object_key( $key );
        if ( ! is_resource( $handle ) ) {
            throw new InvalidArgumentException( 'Expected a readable S3 upload stream.' );
        }
        $stat = fstat( $handle );
        $position = ftell( $handle );
        if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || $position === false ||
            $position < 0 || $position > $stat['size'] ) {
            throw new InvalidArgumentException( 'S3 upload requires an open, sized stream.' );
        }
        $bytes = $stat['size'] - $position;
        if ( $bytes > self::MAX_OBJECT_BYTES ) {
            throw new InvalidArgumentException( 'S3 object exceeds the 5 TiB provider limit.' );
        }
        try {
            if ( $bytes <= self::MULTIPART_THRESHOLD_BYTES ) {
                $this->client->putObject( array(
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'Body' => $handle,
                    'IfNoneMatch' => '*',
                ) );
            } else {
                $class = 'Aws\\S3\\MultipartUploader';
                if ( ! class_exists( $class ) ) {
                    throw new RuntimeException( 'The installed AWS SDK does not provide multipart uploads.' );
                }
                $part_size = max( self::MIN_MULTIPART_PART_BYTES, (int) ceil( $bytes / 10000 ) );
                if ( $part_size > self::MAX_MULTIPART_PART_BYTES ) {
                    throw new InvalidArgumentException( 'S3 upload would exceed the multipart part limit.' );
                }
                $uploader = new $class( $this->client, $handle, array(
                    'bucket' => $this->bucket,
                    'key' => $key,
                    'part_size' => $part_size,
                    'concurrency' => 1,
                    'before_complete' => function ( $command ) {
                        $command['IfNoneMatch'] = '*';
                    },
                ) );
                $uploader->upload();
            }
        } catch ( Throwable $error ) {
            if ( is_a( $error, 'Aws\\Exception\\AwsException' ) ) {
                $this->s3_error( $error, 'upload' );
            }
            throw $error;
        }
        return $bytes;
    }

    public function open_read( $key, $max_bytes = null ) {
        $key = $this->object_key( $key );
        if ( $max_bytes !== null && ( ! is_int( $max_bytes ) || $max_bytes < 0 ) ) {
            throw new InvalidArgumentException( 'Invalid maximum S3 object size.' );
        }
        try {
            $request = array( 'Bucket' => $this->bucket, 'Key' => $key );
            if ( $max_bytes !== null ) {
                // Sidecars and manifests must be size-checked before fetching
                // their bodies. If-Match binds the read to the checked version.
                $head = $this->client->headObject( $request );
                $head_length = isset( $head['ContentLength'] ) ? (int) $head['ContentLength'] : null;
                if ( $head_length === null || $head_length < 0 || $head_length > $max_bytes ) {
                    throw new RuntimeException( 'S3 object size is missing or exceeds the allowed limit.' );
                }
                if ( isset( $head['ETag'] ) && is_string( $head['ETag'] ) && $head['ETag'] !== '' ) {
                    $request['IfMatch'] = $head['ETag'];
                }
            }
            $result = $this->client->getObject( $request );
            $length = isset( $result['ContentLength'] ) ? (int) $result['ContentLength'] : null;
            if ( $length === null || $length < 0 || ( $max_bytes !== null && $length > $max_bytes ) ) {
                throw new RuntimeException( 'S3 object size is missing or exceeds the allowed limit.' );
            }
            return $this->copy_response_body( $result['Body'], $length, $max_bytes );
        } catch ( Throwable $error ) {
            if ( is_a( $error, 'Aws\\Exception\\AwsException' ) ) {
                $this->s3_error( $error, 'download' );
            }
            throw $error;
        }
    }

    public function open_read_range( $key, $offset, $length, $total_bytes ) {
        $key = $this->object_key( $key );
        if ( ! is_int( $offset ) || ! is_int( $length ) || ! is_int( $total_bytes ) ||
            $offset < 0 || $length < 1 || $total_bytes < 1 || $offset + $length > $total_bytes ) {
            throw new InvalidArgumentException( 'Invalid S3 byte range.' );
        }
        $end = $offset + $length - 1;
        try {
            $result = $this->client->getObject( array(
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Range' => 'bytes=' . $offset . '-' . $end,
            ) );
            $content_range = isset( $result['ContentRange'] ) ? $result['ContentRange'] : '';
            if ( ! is_string( $content_range ) || ! preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/D', $content_range, $match ) ||
                (int) $match[1] !== $offset || (int) $match[2] !== $end || (int) $match[3] !== $total_bytes ||
                ! isset( $result['ContentLength'] ) || (int) $result['ContentLength'] !== $length ) {
                throw new RuntimeException( 'S3 did not confirm the exact requested byte range.' );
            }
            return $this->copy_response_body( $result['Body'], $length, $length );
        } catch ( Throwable $error ) {
            if ( is_a( $error, 'Aws\\Exception\\AwsException' ) ) {
                $this->s3_error( $error, 'range download' );
            }
            throw $error;
        }
    }

    public function exists( $key ) {
        $key = $this->object_key( $key );
        try {
            $this->client->headObject( array( 'Bucket' => $this->bucket, 'Key' => $key ) );
            return true;
        } catch ( Throwable $error ) {
            if ( is_a( $error, 'Aws\\Exception\\AwsException' ) ) {
                $code = $error->getAwsErrorCode();
                $status = (int) $error->getStatusCode();
                if ( $status === 404 || in_array( $code, array( 'NoSuchKey', 'NotFound' ), true ) ) {
                    return false;
                }
                $this->s3_error( $error, 'existence check' );
            }
            throw $error;
        }
    }

    public function delete( $key ) {
        $key = $this->object_key( $key );
        try {
            $this->client->deleteObject( array( 'Bucket' => $this->bucket, 'Key' => $key ) );
            return true;
        } catch ( Throwable $error ) {
            if ( is_a( $error, 'Aws\\Exception\\AwsException' ) ) {
                $this->s3_error( $error, 'delete' );
            }
            throw $error;
        }
    }

    public function enumerate() {
        $keys = array();
        $token = null;
        $seen_tokens = array();
        do {
            $request = array( 'Bucket' => $this->bucket, 'Prefix' => $this->prefix, 'MaxKeys' => 1000 );
            if ( $token !== null ) {
                $request['ContinuationToken'] = $token;
            }
            try {
                $page = $this->client->listObjectsV2( $request );
            } catch ( Throwable $error ) {
                if ( is_a( $error, 'Aws\\Exception\\AwsException' ) ) {
                    $this->s3_error( $error, 'inventory' );
                }
                throw $error;
            }
            foreach ( isset( $page['Contents'] ) ? $page['Contents'] : array() as $object ) {
                if ( ! isset( $object['Key'] ) || ! is_string( $object['Key'] ) ||
                    strpos( $object['Key'], $this->prefix ) !== 0 ) {
                    throw new RuntimeException( 'S3 returned an invalid inventory entry.' );
                }
                $relative = substr( $object['Key'], strlen( $this->prefix ) );
                if ( $relative === '' ) {
                    continue;
                }
                try {
                    $this->object_key( $relative );
                } catch ( InvalidArgumentException $error ) {
                    continue;
                }
                $keys[] = $relative;
                if ( count( $keys ) > self::MAX_INVENTORY_ITEMS ) {
                    throw new RuntimeException( 'S3 inventory exceeds the allowed item limit.' );
                }
            }
            $truncated = ! empty( $page['IsTruncated'] );
            $next = isset( $page['NextContinuationToken'] ) ? $page['NextContinuationToken'] : null;
            if ( $truncated && ( ! is_string( $next ) || $next === '' || isset( $seen_tokens[ $next ] ) ) ) {
                throw new RuntimeException( 'S3 returned an invalid inventory continuation token.' );
            }
            if ( $truncated ) {
                $seen_tokens[ $next ] = true;
            }
            $token = $truncated ? $next : null;
        } while ( $token !== null );
        return array_values( array_unique( $keys ) );
    }
}
