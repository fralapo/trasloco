<?php
/**
 * HTTPS WebDAV storage using bounded PHP streams and strict destination checks.
 * Credentials remain in server configuration and never enter object names.
 */
class Trasloco_WebDAV_Storage implements Trasloco_Storage_Adapter, Trasloco_Resumable_Storage_Adapter {
    const MAX_INVENTORY_BYTES = 8388608;
    const MAX_INVENTORY_ITEMS = 10000;
    const MAX_PARTS_INDEX_BYTES = 2097152;
    const RESUMABLE_CHUNK_BYTES = 8388608;
    const MAX_RESUMABLE_PARTS = 10000;

    private $base;
    private $base_path;
    private $host;
    private $url_host;
    private $port;
    private $username;
    private $password;
    private $resolved_hosts;
    private $request_progress_callback;
    private $request_progress_cancelled = false;

    public function __construct( $base_url, $username, $password ) {
        if ( ! function_exists( 'curl_init' ) || ! function_exists( 'wp_http_validate_url' ) ||
            ! is_string( $base_url ) || ! is_string( $username ) || $username === '' ||
            ! is_string( $password ) || $password === '' || strpos( $username, ':' ) !== false ||
            preg_match( '/[\x00-\x1f\x7f]/', $username ) || preg_match( '/[\x00-\x1f\x7f]/', $password ) ) {
            throw new InvalidArgumentException( 'WebDAV requires cURL, an HTTPS URL and server-side credentials.' );
        }
        $parts = parse_url( $base_url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || strtolower( $parts['scheme'] ) !== 'https' ||
            empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
            isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
            ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) ||
            preg_match( '/[\\\\\x00-\x20\x7f]/', $base_url ) ||
            preg_match( '/%(?:2e|2f|5c)/i', isset( $parts['path'] ) ? $parts['path'] : '' ) ||
            preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', isset( $parts['path'] ) ? $parts['path'] : '' ) ||
            ! wp_http_validate_url( $base_url ) ) {
            throw new InvalidArgumentException( 'WebDAV requires a safe, externally validated HTTPS URL without redirects or URL credentials.' );
        }
        $this->host = strtolower( rtrim( trim( $parts['host'], '[]' ), '.' ) );
        $this->url_host = strpos( $this->host, ':' ) !== false ? '[' . $this->host . ']' : $this->host;
        $this->port = 443;
        $this->base_path = isset( $parts['path'] ) && $parts['path'] !== '' ? $parts['path'] : '/';
        $this->base_path = rtrim( $this->base_path, '/' ) . '/';
        $this->base = 'https://' . $this->url_host . $this->base_path;
        $this->username = $username;
        $this->password = $password;
        $this->resolved_hosts = $this->resolve_host();
        if ( empty( $this->resolved_hosts ) && ! filter_var( $this->host, FILTER_VALIDATE_IP ) ) {
            throw new InvalidArgumentException( 'WebDAV host did not resolve to a public address.' );
        }
    }

    private function validate_key( $key ) {
        $valid = is_string( $key ) && strlen( $key ) <= 220 && strpos( $key, '..' ) === false && (
            preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc(?:\.manifest\.json)?|\.manifest\.json|\.parts\.json)?$/D', $key ) ||
            preg_match( '/^trp-[a-f0-9]{64}-[0-9]{6}\.part$/D', $key ) ||
            preg_match( '/^trs-[a-f0-9]{24}\.part$/D', $key )
        );
        if ( ! $valid ) {
            throw new InvalidArgumentException( 'Invalid WebDAV storage key.' );
        }
        return $key;
    }

    private function is_archive_key( $key ) {
        return is_string( $key ) && preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc)?$/D', $key ) && strpos( $key, '..' ) === false;
    }

    /** Resolve once, reject every private/reserved answer, and pin cURL to it. */
    protected function resolve_host() {
        $addresses = array();
        if ( filter_var( $this->host, FILTER_VALIDATE_IP ) ) {
            if ( ! filter_var( $this->host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return array();
            }
            // IP literals cannot be rebound by DNS and CURLOPT_RESOLVE does
            // not need an entry for them.
            return array();
        } else {
            $records = @dns_get_record( $this->host, DNS_A | DNS_AAAA );
            if ( is_array( $records ) ) {
                foreach ( $records as $record ) {
                    if ( ! empty( $record['ip'] ) ) {
                        $addresses[] = $record['ip'];
                    } elseif ( ! empty( $record['ipv6'] ) ) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }
        $addresses = array_values( array_unique( $addresses ) );
        if ( empty( $addresses ) ) {
            return array();
        }
        $pinned_addresses = array();
        foreach ( $addresses as $address ) {
            if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                // Reject mixed public/private DNS answers instead of selecting
                // one and leaving resolution behavior ambiguous.
                return array();
            }
            $pinned_addresses[] = strpos( $address, ':' ) !== false ? '[' . $address . ']' : $address;
        }
        return array( $this->host . ':' . $this->port . ':' . implode( ',', $pinned_addresses ) );
    }

    protected function is_transient_status( $status ) {
        return in_array( (int) $status, array( 408, 425, 429, 500, 502, 503, 504 ), true );
    }

    /**
     * Execute one WebDAV request. Request/response content always uses streams;
     * PROPFIND is bounded to avoid loading an unbounded remote inventory.
     */
    protected function request( $method, $key, $input = null, $output = null, $headers = array(), $max_bytes = null, &$response_headers = null ) {
        $url = $method === 'PROPFIND' ? $this->base : $this->base . rawurlencode( $this->validate_key( $key ) );
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new RuntimeException( 'Unable to initialize the WebDAV request.' );
        }
        $header_lines = array_merge( array( 'Expect:' ), $headers );
        $written = 0;
        $captured_headers = array();
        $options = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_HEADERFUNCTION => function ( $handle, $line ) use ( &$captured_headers ) {
                $length = strlen( $line );
                if ( preg_match( '/^HTTP\/\S+\s+\d{3}/i', $line ) ) {
                    $captured_headers = array();
                } elseif ( strpos( $line, ':' ) !== false ) {
                    list( $name, $value ) = explode( ':', $line, 2 );
                    $captured_headers[ strtolower( trim( $name ) ) ] = trim( $value );
                }
                return $length;
            },
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => $this->resolved_hosts,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_WRITEFUNCTION => function ( $handle, $chunk ) use ( $output, $max_bytes, &$written ) {
                $length = strlen( $chunk );
                if ( $max_bytes !== null && $written + $length > $max_bytes ) {
                    return 0;
                }
                if ( is_resource( $output ) ) {
                    $offset = 0;
                    while ( $offset < $length ) {
                        $count = @fwrite( $output, substr( $chunk, $offset ) );
                        if ( $count === false || $count === 0 ) {
                            return 0;
                        }
                        $offset += $count;
                    }
                }
                $written += $length;
                return $length;
            },
        );
        if ( $method === 'HEAD' ) {
            $options[ CURLOPT_NOBODY ] = true;
        } elseif ( $method === 'PUT' ) {
            if ( ! is_resource( $input ) ) {
                curl_close( $curl );
                throw new InvalidArgumentException( 'Expected a readable WebDAV upload stream.' );
            }
            $stat = fstat( $input );
            $position = ftell( $input );
            if ( ! is_array( $stat ) || ! isset( $stat['size'] ) || $position === false ||
                $position < 0 || $position > $stat['size'] ) {
                curl_close( $curl );
                throw new InvalidArgumentException( 'WebDAV upload requires a stream with a known remaining size.' );
            }
            $options[ CURLOPT_UPLOAD ] = true;
            $options[ CURLOPT_INFILE ] = $input;
            $options[ CURLOPT_INFILESIZE_LARGE ] = $stat['size'] - $position;
            if ( is_callable( $this->request_progress_callback ) && defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
                $callback = $this->request_progress_callback;
                $options[ CURLOPT_NOPROGRESS ] = false;
                $options[ CURLOPT_XFERINFOFUNCTION ] = function ( $handle, $download_total, $downloaded, $upload_total, $uploaded ) use ( $callback ) {
                    if ( call_user_func( $callback ) === false ) {
                        $this->request_progress_cancelled = true;
                        return 1;
                    }
                    return 0;
                };
            }
        } else {
            $options[ CURLOPT_CUSTOMREQUEST ] = $method;
        }
        if ( ! curl_setopt_array( $curl, $options ) ) {
            curl_close( $curl );
            throw new RuntimeException( 'Unable to configure the WebDAV request safely.' );
        }
        $result = curl_exec( $curl );
        $status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        curl_close( $curl );
        $response_headers = $captured_headers;
        if ( $result === false ) {
            if ( $this->request_progress_cancelled ) {
                throw new RuntimeException( 'WebDAV upload was cancelled or its job lease was lost.' );
            }
            $is_range_request = false;
            foreach ( $headers as $header ) {
                if ( stripos( $header, 'Range:' ) === 0 ) {
                    $is_range_request = true;
                    break;
                }
            }
            // A server that ignores Range can exceed the bounded callback and
            // make cURL report a write error. Let the caller reject its 200
            // response explicitly instead of retrying it as a network fault.
            if ( $is_range_request && in_array( $status, array( 200, 206, 416 ), true ) ) {
                return $status;
            }
            throw new Trasloco_Storage_Transient_Exception( 'WebDAV request failed; check the HTTPS service and certificate.' );
        }
        if ( $this->is_transient_status( $status ) ) {
            throw new Trasloco_Storage_Transient_Exception( 'WebDAV service returned a temporary error (' . $status . ').' );
        }
        return $status;
    }

    public function put_stream( $key, $handle, $progress_callback = null ) {
        $key = $this->validate_key( $key );
        if ( ! is_resource( $handle ) ) {
            throw new InvalidArgumentException( 'Expected a readable WebDAV upload stream.' );
        }
        $size = fstat( $handle );
        $position = ftell( $handle );
        if ( ! is_resource( $handle ) || ! is_array( $size ) || $position === false ||
            ! isset( $size['size'] ) || $position > $size['size'] ) {
            throw new InvalidArgumentException( 'WebDAV upload requires an open, sized stream.' );
        }
        $bytes = $size['size'] - $position;
        if ( $progress_callback !== null && ! is_callable( $progress_callback ) ) {
            throw new InvalidArgumentException( 'Invalid WebDAV upload progress callback.' );
        }
        $previous_callback = $this->request_progress_callback;
        $previous_cancelled = $this->request_progress_cancelled;
        $this->request_progress_callback = $progress_callback;
        $this->request_progress_cancelled = false;
        try {
            $status = $this->request( 'PUT', $key, $handle, null, array( 'If-None-Match: *' ) );
        } finally {
            $this->request_progress_callback = $previous_callback;
            $this->request_progress_cancelled = $previous_cancelled;
        }
        if ( $status === 412 || $status === 409 ) {
            throw new RuntimeException( 'WebDAV object already exists; it was not replaced.' );
        }
        if ( $status !== 201 ) {
            throw new RuntimeException( 'WebDAV server did not confirm creation of a new object; upload was not accepted.' );
        }
        return $bytes;
    }

    private function raw_exists( $key ) {
        $status = $this->request( 'HEAD', $key );
        if ( $status === 404 ) {
            return false;
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new RuntimeException( 'WebDAV object status could not be verified.' );
        }
        return true;
    }

    private function part_matches( $key, $bytes, $digest ) {
        if ( ! $this->raw_exists( $key ) ) {
            return false;
        }
        $actual = $this->digest_object( $key, $bytes );
        if ( $actual[0] !== $bytes || ! hash_equals( $digest, $actual[1] ) ) {
            throw new RuntimeException( 'A WebDAV resumable part exists with different content.' );
        }
        return true;
    }

    private function move_part( $stage_key, $part_key, $bytes, $digest ) {
        $destination = $this->base . rawurlencode( $this->validate_key( $part_key ) );
        try {
            $status = $this->request( 'MOVE', $stage_key, null, null,
                array( 'Destination: ' . $destination, 'Overwrite: F' ) );
            if ( in_array( $status, array( 201, 204 ), true ) ) {
                if ( ! $this->part_matches( $part_key, $bytes, $digest ) ) {
                    throw new RuntimeException( 'WebDAV did not preserve the verified part during publication.' );
                }
                if ( $this->raw_exists( $stage_key ) ) {
                    $this->request( 'DELETE', $stage_key );
                }
                return;
            }
        } catch ( Throwable $error ) {
            if ( $this->part_matches( $part_key, $bytes, $digest ) ) {
                if ( $this->raw_exists( $stage_key ) ) {
                    $this->request( 'DELETE', $stage_key );
                }
                return;
            }
            throw $error;
        }
        if ( $this->part_matches( $part_key, $bytes, $digest ) ) {
            if ( $this->raw_exists( $stage_key ) ) {
                $this->request( 'DELETE', $stage_key );
            }
            return;
        }
        throw new RuntimeException( 'WebDAV could not publish a resumable archive part safely.' );
    }

    private function upload_part( $part_key, $chunk, $bytes, $digest, $progress_callback = null ) {
        if ( $this->part_matches( $part_key, $bytes, $digest ) ) {
            return;
        }
        for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
            $stage_key = 'trs-' . bin2hex( random_bytes( 12 ) ) . '.part';
            rewind( $chunk );
            try {
                $heartbeat = $progress_callback === null ? null : function () use ( $progress_callback ) {
                    return call_user_func( $progress_callback );
                };
                $this->put_stream( $stage_key, $chunk, $heartbeat );
            } catch ( Throwable $error ) {
                if ( strpos( $error->getMessage(), 'cancelled or its job lease was lost' ) !== false ) {
                    if ( $this->raw_exists( $stage_key ) ) {
                        $this->request( 'DELETE', $stage_key );
                    }
                    throw $error;
                }
                // A lost PUT response may still mean the private, unique stage
                // object was committed. Reuse it only after exact read-back.
                if ( $this->raw_exists( $stage_key ) ) {
                    $staged = $this->digest_object( $stage_key, $bytes );
                    if ( $staged[0] === $bytes && hash_equals( $digest, $staged[1] ) ) {
                        $this->move_part( $stage_key, $part_key, $bytes, $digest );
                        return;
                    }
                    $this->request( 'DELETE', $stage_key );
                }
                if ( $error instanceof Trasloco_Storage_Transient_Exception && $attempt < 3 ) {
                    usleep( 250000 * $attempt );
                    continue;
                }
                throw $error;
            }
            $staged = $this->digest_object( $stage_key, $bytes );
            if ( $staged[0] !== $bytes || ! hash_equals( $digest, $staged[1] ) ) {
                $this->request( 'DELETE', $stage_key );
                throw new RuntimeException( 'WebDAV resumable part failed read-back verification.' );
            }
            $this->move_part( $stage_key, $part_key, $bytes, $digest );
            return;
        }
        throw new RuntimeException( 'WebDAV resumable part could not be uploaded.' );
    }

    public function put_resumable( $key, $path, $expected_bytes, $expected_sha256, $progress_callback = null, $resume_state = null ) {
        $key = $this->validate_key( $key );
        if ( ! $this->is_archive_key( $key ) || ! is_string( $path ) || ! is_int( $expected_bytes ) ||
            $expected_bytes < 0 || ! is_string( $expected_sha256 ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) ||
            $resume_state !== null ||
            ( $progress_callback !== null && ! is_callable( $progress_callback ) ) ) {
            throw new InvalidArgumentException( 'Invalid WebDAV resumable upload request.' );
        }
        clearstatcache( true, $path );
        $source_stat = @lstat( $path );
        $source_digest = @hash_file( 'sha256', $path );
        if ( ! is_array( $source_stat ) || is_link( $path ) || ! is_file( $path ) ||
            (int) $source_stat['size'] !== $expected_bytes || ! is_string( $source_digest ) ||
            ! hash_equals( $expected_sha256, $source_digest ) ) {
            throw new RuntimeException( 'Local archive changed before resumable upload.' );
        }
        $existing_index = $this->load_parts_index( $key );
        if ( $existing_index !== null ) {
            if ( $existing_index['size_bytes'] !== $expected_bytes ||
                ! hash_equals( $existing_index['sha256'], $expected_sha256 ) ) {
                throw new RuntimeException( 'A different WebDAV archive already exists.' );
            }
            return false;
        }
        $count = $expected_bytes === 0 ? 0 : (int) ceil( $expected_bytes / self::RESUMABLE_CHUNK_BYTES );
        if ( $count > self::MAX_RESUMABLE_PARTS ) {
            throw new RuntimeException( 'Archive exceeds the WebDAV resumable upload part limit.' );
        }
        $input = @fopen( $path, 'rb' );
        if ( $input === false ) {
            throw new RuntimeException( 'Unable to open local archive for resumable upload.' );
        }
        $prefix = 'trp-' . hash( 'sha256', $key . '|' . $expected_sha256 ) . '-';
        $parts = array();
        $offset = 0;
        try {
            for ( $number = 0; $number < $count; ++$number ) {
                $length = min( self::RESUMABLE_CHUNK_BYTES, $expected_bytes - $offset );
                $chunk = @fopen( 'php://temp/maxmemory:8388608', 'w+b' );
                if ( $chunk === false || fseek( $input, $offset, SEEK_SET ) !== 0 ) {
                    if ( is_resource( $chunk ) ) { fclose( $chunk ); }
                    throw new RuntimeException( 'Unable to stage a local WebDAV archive part.' );
                }
                $chunk_hash = hash_init( 'sha256' );
                $copied = 0;
                while ( $copied < $length ) {
                    $data = fread( $input, min( 1048576, $length - $copied ) );
                    if ( $data === false || $data === '' ) {
                        fclose( $chunk );
                        throw new RuntimeException( 'Local archive ended during resumable upload.' );
                    }
                    $written = fwrite( $chunk, $data );
                    if ( $written !== strlen( $data ) ) {
                        fclose( $chunk );
                        throw new RuntimeException( 'Unable to stage a local WebDAV archive part.' );
                    }
                    hash_update( $chunk_hash, $data );
                    $copied += $written;
                }
                $part_digest = hash_final( $chunk_hash );
                $part_key = $prefix . sprintf( '%06d', $number ) . '.part';
                if ( ! $this->part_matches( $part_key, $length, $part_digest ) ) {
                    rewind( $chunk );
                    $heartbeat = $progress_callback === null ? null : function () use ( $progress_callback, $offset, $expected_bytes ) {
                        return call_user_func( $progress_callback, $offset, $expected_bytes );
                    };
                    $this->upload_part( $part_key, $chunk, $length, $part_digest, $heartbeat );
                }
                fclose( $chunk );
                $parts[] = array( 'key' => $part_key, 'bytes' => $length, 'sha256' => $part_digest );
                $offset += $length;
                if ( $progress_callback !== null && call_user_func( $progress_callback, $offset, $expected_bytes ) === false ) {
                    throw new RuntimeException( 'WebDAV resumable upload was cancelled or lost its job lease.' );
                }
            }
        } finally {
            fclose( $input );
        }
        clearstatcache( true, $path );
        $after_stat = @lstat( $path );
        $after_digest = @hash_file( 'sha256', $path );
        if ( ! is_array( $after_stat ) || $after_stat['dev'] !== $source_stat['dev'] ||
            $after_stat['ino'] !== $source_stat['ino'] || $after_stat['size'] !== $source_stat['size'] ||
            $after_stat['mtime'] !== $source_stat['mtime'] || ! is_string( $after_digest ) ||
            ! hash_equals( $expected_sha256, $after_digest ) ) {
            throw new RuntimeException( 'Local archive changed during resumable upload.' );
        }
        $index = array(
            'format' => 'trasloco-webdav-parts-v1',
            'archive' => $key,
            'size_bytes' => $expected_bytes,
            'sha256' => $expected_sha256,
            'chunk_bytes' => self::RESUMABLE_CHUNK_BYTES,
            'parts' => $parts,
        );
        $encoded = json_encode( $index, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_PARTS_INDEX_BYTES ) {
            throw new RuntimeException( 'WebDAV multipart index exceeds its size limit.' );
        }
        $pointer_key = $this->pointer_key( $key );
        $pointer = @fopen( 'php://temp', 'w+b' );
        if ( $pointer === false || fwrite( $pointer, $encoded ) !== strlen( $encoded ) || ! rewind( $pointer ) ) {
            if ( is_resource( $pointer ) ) { fclose( $pointer ); }
            throw new RuntimeException( 'Unable to stage the WebDAV multipart index.' );
        }
        try {
            try {
                $this->put_stream( $pointer_key, $pointer );
            } catch ( Throwable $error ) {
                $published = $this->load_parts_index( $key );
                if ( is_array( $published ) && $published['size_bytes'] === $expected_bytes &&
                    hash_equals( $expected_sha256, $published['sha256'] ) ) {
                    return false;
                }
                throw $error;
            }
        } finally {
            fclose( $pointer );
        }
        return true;
    }

    private function pointer_key( $archive ) {
        return $archive . '.parts.json';
    }

    private function raw_open_read( $key, $max_bytes = null ) {
        $key = $this->validate_key( $key );
        if ( $max_bytes !== null && ( ! is_int( $max_bytes ) || $max_bytes < 0 ) ) {
            throw new InvalidArgumentException( 'Invalid WebDAV response size limit.' );
        }
        $stream = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( $stream === false ) {
            throw new RuntimeException( 'Unable to allocate a private WebDAV download stream.' );
        }
        try {
            $status = $this->request( 'GET', $key, null, $stream, array(), $max_bytes );
            if ( $status < 200 || $status >= 300 || ! rewind( $stream ) ) {
                throw new RuntimeException( 'WebDAV archive cannot be downloaded.' );
            }
            return $stream;
        } catch ( Throwable $error ) {
            fclose( $stream );
            throw $error;
        }
    }

    private function load_parts_index( $archive ) {
        if ( ! $this->is_archive_key( $archive ) ) {
            return null;
        }
        $pointer_key = $this->pointer_key( $archive );
        $status = $this->request( 'HEAD', $pointer_key );
        if ( $status === 404 ) {
            return null;
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new RuntimeException( 'WebDAV multipart archive status could not be verified.' );
        }
        $stream = $this->raw_open_read( $pointer_key, self::MAX_PARTS_INDEX_BYTES );
        try {
            $body = stream_get_contents( $stream, self::MAX_PARTS_INDEX_BYTES + 1 );
        } finally {
            fclose( $stream );
        }
        $index = is_string( $body ) && strlen( $body ) <= self::MAX_PARTS_INDEX_BYTES
            ? json_decode( $body, true ) : null;
        if ( ! is_array( $index ) ||
            ! isset( $index['format'], $index['archive'], $index['size_bytes'], $index['sha256'], $index['chunk_bytes'], $index['parts'] ) ||
            $index['format'] !== 'trasloco-webdav-parts-v1' || $index['archive'] !== $archive ||
            ! is_int( $index['size_bytes'] ) || $index['size_bytes'] < 0 ||
            ! is_string( $index['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $index['sha256'] ) ||
            $index['chunk_bytes'] !== self::RESUMABLE_CHUNK_BYTES || ! is_array( $index['parts'] ) ) {
            throw new RuntimeException( 'WebDAV multipart index is invalid.' );
        }
        $count = $index['size_bytes'] === 0 ? 0 : (int) ceil( $index['size_bytes'] / self::RESUMABLE_CHUNK_BYTES );
        if ( $count > self::MAX_RESUMABLE_PARTS || count( $index['parts'] ) !== $count ) {
            throw new RuntimeException( 'WebDAV multipart index has an invalid part count.' );
        }
        $prefix = 'trp-' . hash( 'sha256', $archive . '|' . $index['sha256'] ) . '-';
        $sum = 0;
        $part_number = 0;
        foreach ( $index['parts'] as $number => $part ) {
            $expected_bytes = min( self::RESUMABLE_CHUNK_BYTES, $index['size_bytes'] - $sum );
            if ( ! is_int( $number ) || $number !== $part_number || ! is_array( $part ) ||
                ! isset( $part['key'], $part['bytes'], $part['sha256'] ) ||
                $part['key'] !== $prefix . sprintf( '%06d', $number ) . '.part' ||
                ! is_int( $part['bytes'] ) || $part['bytes'] !== $expected_bytes ||
                ! is_string( $part['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $part['sha256'] ) ) {
                throw new RuntimeException( 'WebDAV multipart index contains an unsafe part reference.' );
            }
            $sum += $part['bytes'];
            ++$part_number;
        }
        if ( $sum !== $index['size_bytes'] ) {
            throw new RuntimeException( 'WebDAV multipart index size is inconsistent.' );
        }
        return $index;
    }

    private function raw_open_read_range( $key, $offset, $length, $total_bytes ) {
        $key = $this->validate_key( $key );
        if ( ! is_int( $offset ) || ! is_int( $length ) || ! is_int( $total_bytes ) ||
            $offset < 0 || $length <= 0 || $offset > $total_bytes || $length > $total_bytes - $offset ) {
            throw new InvalidArgumentException( 'Invalid WebDAV byte range.' );
        }
        $end = $offset + $length - 1;
        $headers = null;
        $stream = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( $stream === false ) {
            throw new RuntimeException( 'Unable to allocate a private WebDAV range stream.' );
        }
        try {
            $status = $this->request( 'GET', $key, null, $stream,
                array( 'Range: bytes=' . $offset . '-' . $end ), $length + 1, $headers );
            $expected = 'bytes ' . $offset . '-' . $end . '/' . $total_bytes;
            $stat = fstat( $stream );
            if ( $status !== 206 || ! is_array( $headers ) ||
                ! isset( $headers['content-range'] ) || strtolower( $headers['content-range'] ) !== $expected ||
                ! is_array( $stat ) || $stat['size'] !== $length || ! rewind( $stream ) ) {
                throw new RuntimeException( 'WebDAV server did not return the exact requested byte range.' );
            }
            return $stream;
        } catch ( Throwable $error ) {
            fclose( $stream );
            throw $error;
        }
    }

    private function digest_object( $key, $expected_bytes ) {
        $stream = $this->raw_open_read( $key, $expected_bytes );
        try {
            $hash = hash_init( 'sha256' );
            $bytes = 0;
            while ( ! feof( $stream ) ) {
                $part = fread( $stream, 1048576 );
                if ( $part === false || ( $part === '' && ! feof( $stream ) ) ) {
                    throw new RuntimeException( 'WebDAV part ended unexpectedly.' );
                }
                $bytes += strlen( $part );
                hash_update( $hash, $part );
            }
            return array( $bytes, hash_final( $hash ) );
        } finally {
            fclose( $stream );
        }
    }

    public function open_read( $key, $max_bytes = null ) {
        $key = $this->validate_key( $key );
        $index = $this->load_parts_index( $key );
        if ( $index === null ) {
            return $this->raw_open_read( $key, $max_bytes );
        }
        if ( $max_bytes !== null && ( ! is_int( $max_bytes ) || $max_bytes < 0 || $index['size_bytes'] > $max_bytes ) ) {
            throw new RuntimeException( 'Stored multipart archive exceeds the requested size limit.' );
        }
        $stream = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( $stream === false ) {
            throw new RuntimeException( 'Unable to allocate a private WebDAV multipart stream.' );
        }
        try {
            $hash = hash_init( 'sha256' );
            $bytes = 0;
            foreach ( $index['parts'] as $part ) {
                $input = $this->raw_open_read( $part['key'], $part['bytes'] );
                $part_hash = hash_init( 'sha256' );
                $part_bytes = 0;
                try {
                    while ( ! feof( $input ) ) {
                        $data = fread( $input, 1048576 );
                        if ( $data === false || ( $data === '' && ! feof( $input ) ) ) {
                            throw new RuntimeException( 'WebDAV multipart archive ended unexpectedly.' );
                        }
                        $length = strlen( $data );
                        $part_bytes += $length;
                        $bytes += $length;
                        hash_update( $part_hash, $data );
                        hash_update( $hash, $data );
                        if ( fwrite( $stream, $data ) !== $length ) {
                            throw new RuntimeException( 'Unable to stage WebDAV multipart bytes.' );
                        }
                    }
                } finally {
                    fclose( $input );
                }
                if ( $part_bytes !== $part['bytes'] || ! hash_equals( $part['sha256'], hash_final( $part_hash ) ) ) {
                    throw new RuntimeException( 'WebDAV multipart archive part failed verification.' );
                }
            }
            if ( $bytes !== $index['size_bytes'] || ! hash_equals( $index['sha256'], hash_final( $hash ) ) || ! rewind( $stream ) ) {
                throw new RuntimeException( 'WebDAV multipart archive failed full verification.' );
            }
            return $stream;
        } catch ( Throwable $error ) {
            fclose( $stream );
            throw $error;
        }
    }

    public function open_read_range( $key, $offset, $length, $total_bytes ) {
        $key = $this->validate_key( $key );
        $index = $this->load_parts_index( $key );
        if ( $index === null ) {
            return $this->raw_open_read_range( $key, $offset, $length, $total_bytes );
        }
        if ( ! is_int( $offset ) || ! is_int( $length ) || ! is_int( $total_bytes ) ||
            $total_bytes !== $index['size_bytes'] || $offset < 0 || $length <= 0 ||
            $offset > $total_bytes || $length > $total_bytes - $offset ) {
            throw new InvalidArgumentException( 'Invalid WebDAV multipart byte range.' );
        }
        $output = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( $output === false ) {
            throw new RuntimeException( 'Unable to allocate a private WebDAV multipart range.' );
        }
        try {
            $range_end = $offset + $length;
            $base = 0;
            $written = 0;
            foreach ( $index['parts'] as $part ) {
                $part_end = $base + $part['bytes'];
                $from = max( $offset, $base );
                $to = min( $range_end, $part_end );
                if ( $from < $to ) {
                    $part_offset = $from - $base;
                    $part_length = $to - $from;
                    $input = $this->raw_open_read_range( $part['key'], $part_offset, $part_length, $part['bytes'] );
                    try {
                        $copied = stream_copy_to_stream( $input, $output );
                    } finally {
                        fclose( $input );
                    }
                    if ( $copied !== $part_length ) {
                        throw new RuntimeException( 'WebDAV multipart range is incomplete.' );
                    }
                    $written += $copied;
                }
                $base = $part_end;
                if ( $base >= $range_end ) {
                    break;
                }
            }
            if ( $written !== $length || ! rewind( $output ) ) {
                throw new RuntimeException( 'WebDAV multipart range did not match the requested bytes.' );
            }
            return $output;
        } catch ( Throwable $error ) {
            fclose( $output );
            throw $error;
        }
    }

    public function exists( $key ) {
        $key = $this->validate_key( $key );
        if ( $this->is_archive_key( $key ) ) {
            $index = $this->load_parts_index( $key );
            if ( $index !== null ) {
                return true;
            }
        }
        $status = $this->request( 'HEAD', $key );
        if ( $status === 404 ) {
            return false;
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new RuntimeException( 'WebDAV object status could not be verified.' );
        }
        return true;
    }

    public function delete( $key ) {
        $key = $this->validate_key( $key );
        $index = $this->load_parts_index( $key );
        if ( $index !== null ) {
            $pointer_key = $this->pointer_key( $key );
            $pointer_status = $this->request( 'DELETE', $pointer_key );
            if ( $pointer_status !== 404 && ( $pointer_status < 200 || $pointer_status >= 300 ) ) {
                throw new RuntimeException( 'WebDAV multipart archive could not be unpublished safely.' );
            }
            foreach ( $index['parts'] as $part ) {
                $part_status = $this->request( 'DELETE', $part['key'] );
                if ( $part_status !== 404 && ( $part_status < 200 || $part_status >= 300 ) ) {
                    return false;
                }
            }
            return true;
        }
        $status = $this->request( 'DELETE', $key );
        if ( $status === 404 ) {
            return true;
        }
        if ( $status < 200 || $status >= 300 ) {
            throw new RuntimeException( 'WebDAV object could not be deleted.' );
        }
        return true;
    }

    public function enumerate() {
        $body = @fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( $body === false ) {
            throw new RuntimeException( 'Unable to allocate a private WebDAV inventory stream.' );
        }
        try {
            $status = $this->request(
                'PROPFIND',
                null,
                null,
                $body,
                array( 'Depth: 1' ),
                self::MAX_INVENTORY_BYTES
            );
            if ( $status !== 207 || ! rewind( $body ) ) {
                throw new RuntimeException( 'WebDAV inventory request failed.' );
            }
            $xml = stream_get_contents( $body, self::MAX_INVENTORY_BYTES + 1 );
            if ( ! is_string( $xml ) || strlen( $xml ) > self::MAX_INVENTORY_BYTES ) {
                throw new RuntimeException( 'WebDAV inventory exceeds its size limit.' );
            }
            $previous = libxml_use_internal_errors( true );
            try {
                $document = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS );
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
            }
            if ( $document === false ) {
                throw new RuntimeException( 'WebDAV returned an invalid inventory document.' );
            }
            $document->registerXPathNamespace( 'd', 'DAV:' );
            $responses = $document->xpath( '//d:response/d:href' );
            if ( ! is_array( $responses ) || count( $responses ) > self::MAX_INVENTORY_ITEMS ) {
                throw new RuntimeException( 'WebDAV inventory has too many entries.' );
            }
            $items = array();
            $expected_dir = rtrim( rawurldecode( $this->base_path ), '/' );
            foreach ( $responses as $href_node ) {
                $href = trim( (string) $href_node );
                if ( $href === '' || preg_match( '/%(?:2f|5c)/i', $href ) ) {
                    continue;
                }
                $parts = parse_url( $href );
                if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
                    isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
                    ( isset( $parts['scheme'] ) && strtolower( $parts['scheme'] ) !== 'https' ) ||
                    ( isset( $parts['host'] ) && strtolower( rtrim( $parts['host'], '.' ) ) !== $this->host ) ||
                    ( isset( $parts['port'] ) && (int) $parts['port'] !== $this->port ) ) {
                    continue;
                }
                $path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
                if ( preg_match( '#(?:^|/)\.{1,2}(?:/|$)#', $path ) || strpos( $path, '\\' ) !== false ||
                    strpos( $path, "\0" ) !== false || strpos( $path, $expected_dir . '/' ) !== 0 ||
                    dirname( rtrim( $path, '/' ) ) !== $expected_dir ) {
                    continue;
                }
                $key = basename( $path );
                try {
                    $this->validate_key( $key );
                    if ( strpos( $key, 'trs-' ) === 0 || strpos( $key, 'trp-' ) === 0 ) {
                        continue;
                    }
                    if ( substr( $key, -11 ) === '.parts.json' ) {
                        $archive = substr( $key, 0, -11 );
                        if ( $this->is_archive_key( $archive ) ) {
                            $items[] = $archive;
                        }
                    } else {
                        $items[] = $key;
                    }
                } catch ( InvalidArgumentException $ignored ) {
                    // An unexpected remote name is not part of our inventory.
                }
            }
            $items = array_values( array_unique( $items ) );
            sort( $items, SORT_STRING );
            return $items;
        } finally {
            fclose( $body );
        }
    }
}
