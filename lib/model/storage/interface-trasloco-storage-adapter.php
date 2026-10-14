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

/** An adapter may safely retry this failed request without changing its meaning. */
class Trasloco_Storage_Transient_Exception extends RuntimeException {}
