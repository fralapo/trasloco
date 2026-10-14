<?php
/**
 * Serializes interactive, CLI and background exports across resumable requests.
 *
 * The file lock protects each live PHP process. A short owner lease preserves
 * ownership between AJAX requests, which cannot keep a file descriptor open.
 */
class Trasloco_Export_Lock {
	const DEFAULT_LEASE_SECONDS = 1200;
	const MAX_LEASE_SECONDS = 43200;

	private static function path() {
		return TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.trasloco-export.lock';
	}

	private static function open_locked_file() {
		if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
			throw new RuntimeException( 'Unable to create the private backup directory for the export lock.' );
		}
		$path = self::path();
		if ( is_link( $path ) ) {
			throw new RuntimeException( 'The export lock may not be a symbolic link.' );
		}
		$handle = @fopen( $path, 'c+b' );
		if ( $handle === false ) {
			throw new RuntimeException( 'The export lock file cannot be opened.' );
		}
		$opened = fstat( $handle );
		$named  = @lstat( $path );
		if ( is_link( $path ) || $opened === false || $named === false ||
			! isset( $opened['mode'], $opened['nlink'], $opened['ino'], $opened['dev'] ) ||
			( $opened['mode'] & 0170000 ) !== 0100000 || (int) $opened['nlink'] !== 1 ||
			(int) $opened['ino'] !== (int) $named['ino'] || (int) $opened['dev'] !== (int) $named['dev'] ||
			! @chmod( $path, 0600 ) ) {
			fclose( $handle );
			throw new RuntimeException( 'The export lock file is unsafe.' );
		}
		$named = @lstat( $path );
		if ( is_link( $path ) || $named === false || (int) $opened['ino'] !== (int) $named['ino'] ||
			(int) $opened['dev'] !== (int) $named['dev'] ) {
			fclose( $handle );
			throw new RuntimeException( 'The export lock path changed while it was opened.' );
		}
		if ( ! @flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	private static function read_record( $handle ) {
		if ( ! rewind( $handle ) ) {
			throw new RuntimeException( 'The export lock could not be read.' );
		}
		$raw = stream_get_contents( $handle, 4096 );
		if ( ! is_string( $raw ) ) {
			throw new RuntimeException( 'The export lock could not be read.' );
		}
		if ( $raw === '' ) {
			return array( 'owner' => '', 'lease_until' => 0 );
		}
		$record = json_decode( $raw, true );
		if ( ! is_array( $record ) || ! isset( $record['owner'], $record['lease_until'] ) ||
			! is_string( $record['owner'] ) || ! preg_match( '/^(?:[a-f0-9]{64})?$/D', $record['owner'] ) ||
			! is_int( $record['lease_until'] ) || $record['lease_until'] < 0 ) {
			throw new RuntimeException( 'The export lock record is invalid; refusing to start another export.' );
		}
		return $record;
	}

	private static function write_record( $handle, $owner, $lease_until ) {
		$encoded = json_encode( array( 'owner' => $owner, 'lease_until' => (int) $lease_until ) );
		if ( ! is_string( $encoded ) || ! ftruncate( $handle, 0 ) || ! rewind( $handle ) ) {
			throw new RuntimeException( 'The export lock record could not be updated.' );
		}
		$offset = 0;
		while ( $offset < strlen( $encoded ) ) {
			$written = fwrite( $handle, substr( $encoded, $offset ) );
			if ( $written === false || $written === 0 ) {
				throw new RuntimeException( 'The export lock record could not be written.' );
			}
			$offset += $written;
		}
		if ( ! fflush( $handle ) ) {
			throw new RuntimeException( 'The export lock record could not be flushed.' );
		}
	}

	private static function valid_owner( $owner ) {
		return is_string( $owner ) && preg_match( '/^[a-f0-9]{64}$/D', $owner );
	}

	private static function valid_lease( $lease_seconds ) {
		return is_int( $lease_seconds ) && $lease_seconds >= 60 && $lease_seconds <= self::MAX_LEASE_SECONDS;
	}

	/** Return the exclusively locked handle, or false when another owner is active. */
	public static function acquire( $owner, $lease_seconds = self::DEFAULT_LEASE_SECONDS ) {
		if ( ! self::valid_owner( $owner ) || ! self::valid_lease( $lease_seconds ) ) {
			throw new InvalidArgumentException( 'Invalid export lock owner or lease duration.' );
		}
		$handle = self::open_locked_file();
		if ( $handle === false ) {
			return false;
		}
		try {
			$record = self::read_record( $handle );
			if ( $record['owner'] !== '' && $record['lease_until'] > time() &&
				! hash_equals( $record['owner'], $owner ) ) {
				flock( $handle, LOCK_UN );
				fclose( $handle );
				return false;
			}
			self::write_record( $handle, $owner, time() + $lease_seconds );
			return $handle;
		} catch ( Throwable $e ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw $e;
		}
	}

	/** Extend a worker's lease while it retains the OS-level lock. */
	public static function renew_held( $handle, $owner, $lease_seconds = self::DEFAULT_LEASE_SECONDS ) {
		if ( ! is_resource( $handle ) || ! self::valid_owner( $owner ) || ! self::valid_lease( $lease_seconds ) ) {
			return false;
		}
		try {
			$record = self::read_record( $handle );
			if ( ! hash_equals( $record['owner'], $owner ) ) {
				return false;
			}
			self::write_record( $handle, $owner, time() + $lease_seconds );
			return true;
		} catch ( Throwable $ignored ) {
			return false;
		}
	}

	/** Clear a matching owner lease and always close the process lock. */
	public static function release( $handle, $owner ) {
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		$released = false;
		try {
			$record = self::read_record( $handle );
			if ( self::valid_owner( $owner ) && hash_equals( $record['owner'], $owner ) ) {
				self::write_record( $handle, '', 0 );
				$released = true;
			}
		} catch ( Throwable $ignored ) {
			// Keep a possibly corrupt record fail-closed until its lease expires.
		} finally {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}
		return $released;
	}

	/** End one AJAX request while preserving its operation lease for the next stage. */
	public static function suspend( $handle, $owner ) {
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		$valid = false;
		try {
			$record = self::read_record( $handle );
			$valid = self::valid_owner( $owner ) && hash_equals( $record['owner'], $owner ) &&
				$record['lease_until'] > time();
		} catch ( Throwable $ignored ) {
			$valid = false;
		} finally {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}
		return $valid;
	}
}
