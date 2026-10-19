<?php
/** Immutable, read-back-verified transfer for local content-addressed snapshots. */
class Trasloco_Incremental_Transfer {
    private $remote;
    private $store;

    public function __construct( $remote, $store ) {
        if ( ! $remote instanceof Trasloco_Storage_Adapter || ! $store instanceof Trasloco_Incremental_Store ) {
            throw new InvalidArgumentException( 'A supported storage adapter and incremental store are required.' );
        }
        $this->remote = $remote;
        $this->store = $store;
    }

    private function object_key( $digest ) { return 'tro-' . $digest . '.obj'; }
    private function catalog_key( $archive ) { return 'trc-' . hash( 'sha256', $archive ) . '.json'; }

    private function matches( $key, $size, $digest ) {
        if ( ! $this->remote->exists( $key ) ) { return false; }
        $stream = $this->remote->open_read( $key, $size );
        try {
            $hash = hash_init( 'sha256' ); $bytes = 0;
            while ( ! feof( $stream ) ) {
                $part = fread( $stream, 1048576 );
                if ( $part === false || ( $part === '' && ! feof( $stream ) ) ) { throw new RuntimeException( 'Remote incremental object stream failed.' ); }
                $bytes += strlen( $part );
                if ( $bytes > $size ) { throw new RuntimeException( 'Remote incremental object exceeds its expected size.' ); }
                hash_update( $hash, $part );
            }
            if ( $bytes !== $size || ! hash_equals( $digest, hash_final( $hash ) ) ) {
                throw new RuntimeException( 'Remote incremental object exists with different content: ' . $key );
            }
        } finally { fclose( $stream ); }
        return true;
    }

    private function publish( $key, $bytes, $digest ) {
        if ( $this->matches( $key, strlen( $bytes ), $digest ) ) { return false; }
        $stream = fopen( 'php://temp/maxmemory:2097152', 'w+b' );
        if ( ! $stream || fwrite( $stream, $bytes ) !== strlen( $bytes ) || ! rewind( $stream ) ) {
            if ( is_resource( $stream ) ) { fclose( $stream ); }
            throw new RuntimeException( 'Unable to stage incremental catalog publication.' );
        }
        try {
            try { $this->remote->put_stream( $key, $stream ); }
            catch ( Throwable $error ) { if ( ! $this->matches( $key, strlen( $bytes ), $digest ) ) { throw $error; } return true; }
        } finally { fclose( $stream ); }
        if ( ! $this->matches( $key, strlen( $bytes ), $digest ) ) { throw new RuntimeException( 'Remote incremental catalog failed read-back verification.' ); }
        return true;
    }

    public function upload( $archive, $progress = null ) {
        if ( $progress !== null && ! is_callable( $progress ) ) {
            throw new InvalidArgumentException( 'Invalid incremental transfer progress callback.' );
        }
        $snapshot = $this->store->export_snapshot( $archive, function ( $completed, $total, $bytes ) use ( $progress ) {
            return $progress === null || call_user_func( $progress, $completed, $total, $bytes, 'validating' ) !== false;
        } );
        $new_objects = 0; $bytes = 0; $completed_objects = 0; $completed_bytes = 0;
        $total_objects = count( $snapshot['objects'] );
        foreach ( $snapshot['objects'] as $digest => $size ) {
            $key = $this->object_key( $digest );
            if ( ! $this->matches( $key, $size, $digest ) ) {
                $stream = $this->store->open_object_for_transfer( $digest, $size );
                try { $this->remote->put_stream( $key, $stream ); } catch ( Throwable $error ) {
                    if ( ! $this->matches( $key, $size, $digest ) ) { throw $error; }
                } finally { fclose( $stream ); }
                if ( ! $this->matches( $key, $size, $digest ) ) { throw new RuntimeException( 'Remote incremental object failed read-back verification.' ); }
                ++$new_objects; $bytes += $size;
            }
            ++$completed_objects; $completed_bytes += $size;
            if ( $progress !== null && call_user_func( $progress, $completed_objects, $total_objects, $completed_bytes, 'uploading' ) === false ) {
                throw new RuntimeException( 'Incremental snapshot upload was cancelled or its lease was lost.' );
            }
        }
        $catalog_key = $this->catalog_key( $archive );
        $catalog_digest = hash( 'sha256', $snapshot['catalog'] );
        $published = $this->publish( $catalog_key, $snapshot['catalog'], $catalog_digest );
        return array( 'status' => $new_objects || $published ? 'uploaded' : 'already_present',
            'archive' => $archive, 'objects_uploaded' => $new_objects, 'bytes_uploaded' => $bytes,
            'catalog_key' => $catalog_key );
    }

    public function download( $archive, $progress = null ) {
        if ( $progress !== null && ! is_callable( $progress ) ) {
            throw new InvalidArgumentException( 'Invalid incremental transfer progress callback.' );
        }
        $key = $this->catalog_key( $archive );
        $stream = $this->remote->open_read( $key, Trasloco_Incremental_Store::MAX_CATALOG_BYTES );
        try {
            $json = stream_get_contents( $stream, Trasloco_Incremental_Store::MAX_CATALOG_BYTES + 1 );
            if ( ! is_string( $json ) || strlen( $json ) > Trasloco_Incremental_Store::MAX_CATALOG_BYTES ) {
                throw new RuntimeException( 'Remote incremental catalog exceeds its size limit.' );
            }
        } finally { fclose( $stream ); }
        $metadata = json_decode( $json, true );
        if ( ! is_array( $metadata ) || ! isset( $metadata['archive'] ) || $metadata['archive'] !== $archive ) {
            throw new RuntimeException( 'Remote incremental catalog does not match the requested archive.' );
        }
        return $this->store->import_snapshot( $json, function ( $digest, $size ) {
            return $this->remote->open_read( $this->object_key( $digest ), $size );
        }, $progress === null ? null : function ( $completed, $total, $bytes ) use ( $progress ) {
            return call_user_func( $progress, $completed, $total, $bytes, 'downloading' );
        } );
    }
}
