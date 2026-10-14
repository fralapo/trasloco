<?php
/**
 * HTTPS WebDAV storage using bounded PHP streams and strict destination checks.
 * Credentials remain in server configuration and never enter object names.
 */
class Trasloco_WebDAV_Storage implements Trasloco_Storage_Adapter {
    const MAX_INVENTORY_BYTES = 8388608;
    const MAX_INVENTORY_ITEMS = 10000;

    private $base;
    private $base_path;
    private $host;
    private $url_host;
    private $port;
    private $username;
    private $password;
    private $resolved_hosts;

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
        if ( ! is_string( $key ) || strlen( $key ) > 220 ||
            ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress(?:\.enc(?:\.manifest\.json)?|\.manifest\.json)?$/D', $key ) ||
            strpos( $key, '..' ) !== false ) {
            throw new InvalidArgumentException( 'Invalid WebDAV storage key.' );
        }
        return $key;
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

    /**
     * Execute one WebDAV request. Request/response content always uses streams;
     * PROPFIND is bounded to avoid loading an unbounded remote inventory.
     */
    protected function request( $method, $key, $input = null, $output = null, $headers = array(), $max_bytes = null ) {
        $url = $method === 'PROPFIND' ? $this->base : $this->base . rawurlencode( $this->validate_key( $key ) );
        $curl = curl_init( $url );
        if ( $curl === false ) {
            throw new RuntimeException( 'Unable to initialize the WebDAV request.' );
        }
        $header_lines = array_merge( array( 'Expect:' ), $headers );
        $written = 0;
        $options = array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 300,
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
        if ( $result === false ) {
            throw new RuntimeException( 'WebDAV request failed; check the HTTPS service and certificate.' );
        }
        return $status;
    }

    public function put_stream( $key, $handle ) {
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
        $status = $this->request( 'PUT', $key, $handle, null, array( 'If-None-Match: *' ) );
        if ( $status === 412 || $status === 409 ) {
            throw new RuntimeException( 'WebDAV object already exists; it was not replaced.' );
        }
        if ( $status !== 201 ) {
            throw new RuntimeException( 'WebDAV server did not confirm creation of a new object; upload was not accepted.' );
        }
        return $bytes;
    }

    public function open_read( $key, $max_bytes = null ) {
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

    public function exists( $key ) {
        $this->validate_key( $key );
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
        $this->validate_key( $key );
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
                    $items[] = $key;
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
