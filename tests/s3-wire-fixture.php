<?php
/** Minimal stateful S3-compatible HTTP fixture for the actual AWS SDK handler. */
$state_path = getenv( 'TRASLOCO_S3_WIRE_STATE' );
if ( ! is_string( $state_path ) || $state_path === '' ) {
    http_response_code( 500 );
    exit;
}
$state_json = is_file( $state_path ) ? file_get_contents( $state_path ) : false;
$state = is_string( $state_json ) ? json_decode( $state_json, true ) : null;
if ( ! is_array( $state ) ) {
    $state = array( 'objects' => array(), 'requests' => array() );
}
$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$path = rawurldecode( parse_url( $request_uri, PHP_URL_PATH ) );
$segments = explode( '/', ltrim( $path, '/' ) );
$bucket = array_shift( $segments );
$key = implode( '/', $segments );
$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
$body = file_get_contents( 'php://input' );
$state['requests'][] = array(
    'method' => $method,
    'uri' => $request_uri,
    'bucket' => $bucket,
    'key' => $key,
    'body' => is_string( $body ) ? $body : '',
    'if_none_match' => isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '',
    'if_match' => isset( $_SERVER['HTTP_IF_MATCH'] ) ? $_SERVER['HTTP_IF_MATCH'] : '',
    'range' => isset( $_SERVER['HTTP_RANGE'] ) ? $_SERVER['HTTP_RANGE'] : '',
    'authorization' => isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '',
);

function s3_wire_save_state( $path, $state ) {
    file_put_contents( $path, json_encode( $state ) );
}

function s3_wire_error( $status, $code, $message ) {
    http_response_code( $status );
    header( 'Content-Type: application/xml' );
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . htmlspecialchars( $code, ENT_XML1 ) .
        '</Code><Message>' . htmlspecialchars( $message, ENT_XML1 ) . '</Message><RequestId>fixture</RequestId></Error>';
}

if ( $bucket !== 'trasloco-backups' ) {
    s3_wire_error( 404, 'NoSuchBucket', 'Unknown fixture bucket.' );
} elseif ( $method === 'PUT' ) {
    if ( isset( $state['objects'][ $key ] ) && isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && $_SERVER['HTTP_IF_NONE_MATCH'] === '*' ) {
        s3_wire_error( 412, 'PreconditionFailed', 'Object already exists.' );
    } else {
        $state['objects'][ $key ] = is_string( $body ) ? $body : '';
        s3_wire_save_state( $state_path, $state );
        header( 'ETag: "' . md5( $state['objects'][ $key ] ) . '"' );
        http_response_code( 200 );
    }
} elseif ( $method === 'HEAD' ) {
    if ( ! isset( $state['objects'][ $key ] ) ) {
        s3_wire_error( 404, 'NoSuchKey', 'Object is missing.' );
    } else {
        header( 'Content-Length: ' . strlen( $state['objects'][ $key ] ) );
        header( 'ETag: "' . md5( $state['objects'][ $key ] ) . '"' );
        http_response_code( 200 );
    }
} elseif ( $method === 'GET' && isset( $_GET['list-type'] ) && $_GET['list-type'] === '2' ) {
    $prefix = isset( $_GET['prefix'] ) ? (string) $_GET['prefix'] : '';
    $contents = '';
    $count = 0;
    foreach ( $state['objects'] as $object_key => $object_body ) {
        if ( strpos( $object_key, $prefix ) !== 0 ) {
            continue;
        }
        ++$count;
        $contents .= '<Contents><Key>' . htmlspecialchars( $object_key, ENT_XML1 ) . '</Key>' .
            '<LastModified>2026-10-14T00:00:00.000Z</LastModified><ETag>"' . md5( $object_body ) .
            '"</ETag><Size>' . strlen( $object_body ) . '</Size><StorageClass>STANDARD</StorageClass></Contents>';
    }
    header( 'Content-Type: application/xml' );
    echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' .
        '<Name>trasloco-backups</Name><Prefix>' . htmlspecialchars( $prefix, ENT_XML1 ) . '</Prefix>' .
        '<KeyCount>' . $count . '</KeyCount><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>' .
        $contents . '</ListBucketResult>';
} elseif ( $method === 'GET' ) {
    if ( ! isset( $state['objects'][ $key ] ) ) {
        s3_wire_error( 404, 'NoSuchKey', 'Object is missing.' );
    } elseif ( isset( $_SERVER['HTTP_IF_MATCH'] ) &&
        trim( $_SERVER['HTTP_IF_MATCH'], '"' ) !== md5( $state['objects'][ $key ] ) ) {
        s3_wire_error( 412, 'PreconditionFailed', 'Object version changed.' );
    } else {
        $object_body = $state['objects'][ $key ];
        header( 'ETag: "' . md5( $object_body ) . '"' );
        if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/^bytes=(\d+)-(\d+)$/D', $_SERVER['HTTP_RANGE'], $match ) ) {
            $start = (int) $match[1];
            $end = (int) $match[2];
            if ( $end >= strlen( $object_body ) ) {
                s3_wire_error( 416, 'InvalidRange', 'Requested range is outside the object.' );
            } else {
                $range_body = substr( $object_body, $start, $end - $start + 1 );
                http_response_code( 206 );
                header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . strlen( $object_body ) );
                header( 'Content-Length: ' . strlen( $range_body ) );
                echo $range_body;
            }
        } else {
            header( 'Content-Length: ' . strlen( $object_body ) );
            echo $object_body;
        }
    }
} elseif ( $method === 'DELETE' ) {
    unset( $state['objects'][ $key ] );
    s3_wire_save_state( $state_path, $state );
    http_response_code( 204 );
} else {
    s3_wire_error( 405, 'MethodNotAllowed', 'Unsupported fixture operation.' );
}

if ( ! headers_sent() ) {
    header( 'x-amz-request-id: fixture-request' );
}
$state['requests'][ count( $state['requests'] ) - 1 ]['authenticated'] =
    strpos( $state['requests'][ count( $state['requests'] ) - 1 ]['authorization'], 'AWS4-HMAC-SHA256' ) === 0;
s3_wire_save_state( $state_path, $state );
