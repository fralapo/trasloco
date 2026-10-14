<?php
/**
 * Stream-based storage contract shared by local and future cloud providers.
 * No HTTP credentials or remote URLs belong in backup manifests.
 */
interface Trasloco_Storage_Adapter {
    public function put_stream( $key, $handle );
    public function open_read( $key, $max_bytes = null );
    /** Open exactly one verified byte range from a sized object. */
    public function open_read_range( $key, $offset, $length, $total_bytes );
    public function exists( $key );
    public function delete( $key );
    public function enumerate();
}

/** Optional capability for providers that can persist archive parts safely. */
interface Trasloco_Resumable_Storage_Adapter {
    /**
     * Publish an archive through immutable, verified parts and a final marker.
     * The marker is visible only after every part has been read back and checked.
     */
    /**
     * Resume immutable remote parts from an optional provider-specific job
     * checkpoint. Progress callbacks may receive that checkpoint as argument 3.
     */
    public function put_resumable( $key, $path, $expected_bytes, $expected_sha256, $progress_callback = null, $resume_state = null );
}

/** An adapter may safely retry this failed request without changing its meaning. */
class Trasloco_Storage_Transient_Exception extends RuntimeException {}
