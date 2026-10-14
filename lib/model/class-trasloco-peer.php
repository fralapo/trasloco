<?php
/**
 * Authenticated, resumable WordPress-to-WordPress archive transfer.
 * Incoming archives remain private until their complete digest is verified.
 */
class Trasloco_Peer {
	const CHUNK_BYTES = 524288;

	private static function validate_archive( $archive ) {
		if ( ! is_string( $archive ) || strlen( $archive ) > 220 ||
			! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $archive ) ||
			strpos( $archive, '..' ) !== false ) {
			throw new InvalidArgumentException( 'Archive name must be a safe .wpress basename.' );
		}
	}

	private static function validate_base_url( $base ) {
		if ( ! is_string( $base ) || strlen( $base ) > 2048 ) {
			throw new InvalidArgumentException( 'Peer URL must be an HTTPS site URL.' );
		}
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ||
			strtolower( $parts['scheme'] ) !== 'https' || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
			( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) ||
			! wp_http_validate_url( $base ) ) {
			throw new InvalidArgumentException( 'Peer URL must be a safe HTTPS site URL without credentials, query or fragment.' );
		}
		return rtrim( $base, '/' );
	}

	private static function endpoint( $base, $archive, $suffix ) {
		return $base . '/wp-json/trasloco/v1/backups/' . rawurlencode( $archive ) . '/' . $suffix;
	}

	private static function request( $url, $auth, $max_bytes ) {
		$result = wp_safe_remote_get( $url, array(
			'timeout' => 45,
			'redirection' => 0,
			'sslverify' => true,
			'limit_response_size' => $max_bytes + 1,
			'headers' => array( 'Accept' => 'application/json', 'Authorization' => 'Basic ' . $auth ),
		) );
		if ( is_wp_error( $result ) || (int) wp_remote_retrieve_response_code( $result ) !== 200 ) {
			throw new RuntimeException( 'Remote backup transfer failed or was denied.' );
		}
		$body = wp_remote_retrieve_body( $result );
		if ( ! is_string( $body ) || strlen( $body ) > $max_bytes ) {
			throw new RuntimeException( 'Remote backup response is invalid or oversized.' );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Remote backup response is not valid JSON.' );
		}
		return $data;
	}

	private static function private_folder( $base, $archive, $username ) {
		$root_setting = defined( 'TRASLOCO_PRIVATE_TRANSFER_DIR' ) ? TRASLOCO_PRIVATE_TRANSFER_DIR : sys_get_temp_dir();
		if ( ! is_string( $root_setting ) || is_link( $root_setting ) ) {
			throw new RuntimeException( 'Private transfer directory must not be a symbolic link.' );
		}
		$root = realpath( $root_setting );
		$content = realpath( WP_CONTENT_DIR );
		$webroot = realpath( ABSPATH );
		if ( $root === false || ! is_dir( $root ) || ! is_writable( $root ) || is_link( $root ) ||
			( $content && ( $root === $content || strpos( $root, $content . DIRECTORY_SEPARATOR ) === 0 ) ) ||
			( $webroot && ( $root === $webroot || strpos( $root, $webroot . DIRECTORY_SEPARATOR ) === 0 ) ) ) {
			throw new RuntimeException( 'Private transfer directory must exist and be outside the public WordPress tree.' );
		}
		$identity = hash( 'sha256', $base . '|' . $username . '|' . $archive );
		$folder = $root . DIRECTORY_SEPARATOR . 'trasloco-peer-' . $identity;
		if ( ! is_dir( $folder ) ) {
			if ( file_exists( $folder ) || is_link( $folder ) || ! @mkdir( $folder, 0700 ) ) {
				throw new RuntimeException( 'Cannot create private transfer directory.' );
			}
		}
		$real_folder = realpath( $folder );
		if ( $real_folder !== $folder || is_link( $folder ) || ! is_writable( $folder ) ) {
			throw new RuntimeException( 'Private transfer directory is unsafe.' );
		}
		@chmod( $folder, 0700 );
		return $folder;
	}

	private static function write_all( $stream, $bytes ) {
		$length = strlen( $bytes );
		$written = 0;
		while ( $written < $length ) {
			$count = @fwrite( $stream, substr( $bytes, $written ) );
			if ( $count === false || $count === 0 ) {
				throw new RuntimeException( 'Failed writing the downloaded archive.' );
			}
			$written += $count;
		}
	}

	private static function publish_manifest( $archive_path, $bytes, $sha256 ) {
		$manifest_path = Trasloco_Integrity::manifest_path( $archive_path );
		if ( is_link( $manifest_path ) ) {
			throw new RuntimeException( 'Refusing to replace a symbolic-link manifest.' );
		}
		if ( file_exists( $manifest_path ) ) {
			$result = Trasloco_Integrity::verify( $archive_path );
			$digest = isset( $result['sha256'] ) ? $result['sha256'] : false;
			if ( $result['status'] !== 'verified' || ! is_string( $digest ) || ! hash_equals( $sha256, $digest ) ) {
				throw new RuntimeException( 'An incompatible local integrity manifest already exists.' );
			}
			return;
		}
		$body = wp_json_encode( array(
			'manifest_version' => Trasloco_Integrity::VERSION,
			'algorithm' => 'sha256',
			'size_bytes' => $bytes,
			'sha256' => $sha256,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $body ) ) {
			throw new RuntimeException( 'Cannot encode local integrity manifest.' );
		}
		$temp = @tempnam( dirname( $manifest_path ), '.trasloco-peer-manifest-' );
		if ( $temp === false || realpath( dirname( $temp ) ) !== realpath( dirname( $manifest_path ) ) ) {
			if ( is_string( $temp ) ) { @unlink( $temp ); }
			throw new RuntimeException( 'Cannot allocate a private integrity manifest.' );
		}
		$written = file_put_contents( $temp, $body, LOCK_EX );
		if ( $written !== strlen( $body ) ) {
			@unlink( $temp );
			throw new RuntimeException( 'Cannot prepare local integrity manifest.' );
		}
		if ( ! @link( $temp, $manifest_path ) ) {
			@unlink( $temp );
			if ( is_file( $manifest_path ) ) {
				return self::publish_manifest( $archive_path, $bytes, $sha256 );
			}
			throw new RuntimeException( 'Cannot publish integrity manifest without overwriting an existing file.' );
		}
		@unlink( $temp );
	}

	/** Pull a verified archive from a remote Trasloco site, preserving resumable state. */
	public static function pull( $base, $archive, $username, $password ) {
		require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
		self::validate_archive( $archive );
		$base = self::validate_base_url( $base );
		if ( ! is_string( $username ) || $username === '' || strpos( $username, ':' ) !== false ||
			preg_match( '/[\r\n\x00-\x1f\x7f]/', $username ) || ! is_string( $password ) || $password === '' ||
			preg_match( '/[\r\n\x00-\x1f\x7f]/', $password ) ) {
			throw new InvalidArgumentException( 'Provide a dedicated WordPress Application Password through the environment.' );
		}
		if ( ! is_dir( TRASLOCO_BACKUPS_PATH ) && ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
			throw new RuntimeException( 'Cannot create local backup directory.' );
		}
		$backup_root = realpath( TRASLOCO_BACKUPS_PATH );
		if ( $backup_root === false || is_link( TRASLOCO_BACKUPS_PATH ) || ! is_writable( $backup_root ) ) {
			throw new RuntimeException( 'Local backup directory is unsafe or not writable.' );
		}
		$destination = trasloco_backup_path( array( 'archive' => $archive ) );
		$auth = base64_encode( $username . ':' . $password );
		$remote = self::request( self::endpoint( $base, $archive, 'manifest' ), $auth, 4096 );
		if ( ! isset( $remote['archive'], $remote['bytes'], $remote['sha256'] ) ||
			$remote['archive'] !== $archive || ! is_int( $remote['bytes'] ) || $remote['bytes'] <= 0 ||
			! is_string( $remote['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $remote['sha256'] ) ) {
			throw new RuntimeException( 'Remote site did not supply a valid verified backup manifest.' );
		}
		if ( is_link( $destination ) ) {
			throw new RuntimeException( 'Refusing to use a symbolic-link destination.' );
		}
		if ( file_exists( $destination ) ) {
			$existing_digest = is_file( $destination ) ? hash_file( 'sha256', $destination ) : false;
			if ( ! is_file( $destination ) || filesize( $destination ) !== $remote['bytes'] ||
				! is_string( $existing_digest ) || ! hash_equals( $remote['sha256'], $existing_digest ) ) {
				throw new RuntimeException( 'Backup already exists locally with different contents; refusing to overwrite it.' );
			}
			self::publish_manifest( $destination, $remote['bytes'], $remote['sha256'] );
			$result = Trasloco_Integrity::verify( $destination );
			$existing_digest = isset( $result['sha256'] ) ? $result['sha256'] : false;
			if ( $result['status'] !== 'verified' || ! is_string( $existing_digest ) || ! hash_equals( $remote['sha256'], $existing_digest ) ) {
				throw new RuntimeException( 'Existing downloaded archive could not be finalized safely.' );
			}
			return $destination;
		}

		$folder = self::private_folder( $base, $archive, $username );
		$payload = $folder . DIRECTORY_SEPARATOR . 'download.part';
		$metadata = $folder . DIRECTORY_SEPARATOR . 'transfer.json';
		if ( is_link( $payload ) || is_link( $metadata ) ) {
			throw new RuntimeException( 'Unsafe partial transfer files.' );
		}
		$expected = array( 'archive' => $archive, 'bytes' => $remote['bytes'], 'sha256' => $remote['sha256'] );
		if ( file_exists( $metadata ) ) {
			$prior = is_file( $metadata ) && filesize( $metadata ) <= 4096
				? json_decode( (string) file_get_contents( $metadata ), true ) : null;
			if ( ! is_array( $prior ) || ! isset( $prior['archive'], $prior['bytes'], $prior['sha256'] ) ||
				$prior['archive'] !== $expected['archive'] || $prior['bytes'] !== $expected['bytes'] ||
				$prior['sha256'] !== $expected['sha256'] ) {
				throw new RuntimeException( 'Remote backup changed or private resume metadata is invalid.' );
			}
		} elseif ( file_exists( $payload ) ) {
			throw new RuntimeException( 'Partial archive exists without its transfer manifest.' );
		} else {
			$temp_meta = $folder . DIRECTORY_SEPARATOR . '.transfer-' . bin2hex( random_bytes( 8 ) );
			$encoded = wp_json_encode( $expected );
			if ( ! is_string( $encoded ) || file_put_contents( $temp_meta, $encoded, LOCK_EX ) !== strlen( $encoded ) ) {
				@unlink( $temp_meta );
				throw new RuntimeException( 'Unable to save private transfer metadata.' );
			}
			@chmod( $temp_meta, 0600 );
			if ( ! @link( $temp_meta, $metadata ) ) {
				@unlink( $temp_meta );
				throw new RuntimeException( 'Unable to publish private transfer metadata.' );
			}
			@unlink( $temp_meta );
		}

		$stream = @fopen( $payload, 'c+b' );
		if ( $stream === false ) {
			throw new RuntimeException( 'Cannot open partial archive.' );
		}
		@chmod( $payload, 0600 );
		try {
			$stat = fstat( $stream );
			if ( ! is_array( $stat ) || $stat['nlink'] !== 1 || ! flock( $stream, LOCK_EX | LOCK_NB ) ) {
				throw new RuntimeException( 'Partial archive is unsafe or another worker is using it.' );
			}
			$offset = (int) $stat['size'];
			if ( $offset > $remote['bytes'] ) {
				throw new RuntimeException( 'Partial archive length exceeds the remote manifest.' );
			}
			// A process may stop during a write; discard its incomplete final frame before resuming.
			$offset -= $offset % self::CHUNK_BYTES;
			if ( ! ftruncate( $stream, $offset ) ) {
				throw new RuntimeException( 'Could not safely align partial archive for resume.' );
			}
			while ( $offset < $remote['bytes'] ) {
				$url = self::endpoint( $base, $archive, 'chunk' ) . '?offset=' . $offset;
				$chunk = self::request( $url, $auth, 720000 );
				$expected_length = min( self::CHUNK_BYTES, $remote['bytes'] - $offset );
				if ( ! isset( $chunk['offset'], $chunk['length'], $chunk['sha256'], $chunk['data'] ) ||
					! is_int( $chunk['offset'] ) || $chunk['offset'] !== $offset ||
					! is_int( $chunk['length'] ) || $chunk['length'] !== $expected_length ||
					! is_string( $chunk['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $chunk['sha256'] ) ||
					! is_string( $chunk['data'] ) ) {
					throw new RuntimeException( 'Invalid remote transfer frame.' );
				}
				$bytes = base64_decode( $chunk['data'], true );
				if ( $bytes === false || strlen( $bytes ) !== $expected_length ||
					! hash_equals( $chunk['sha256'], hash( 'sha256', $bytes ) ) ) {
					throw new RuntimeException( 'Remote frame failed integrity verification.' );
				}
				if ( fseek( $stream, $offset, SEEK_SET ) !== 0 ) {
					throw new RuntimeException( 'Could not seek partial archive.' );
				}
				self::write_all( $stream, $bytes );
				if ( ! fflush( $stream ) ) {
					throw new RuntimeException( 'Could not flush partial archive.' );
				}
				$offset += strlen( $bytes );
			}
			if ( ! hash_equals( $remote['sha256'], hash_file( 'sha256', $payload ) ) ) {
				throw new RuntimeException( 'Complete peer download failed SHA-256 verification.' );
			}
			flock( $stream, LOCK_UN );
		} finally {
			fclose( $stream );
		}

		if ( is_link( $destination ) || ! @link( $payload, $destination ) ) {
			throw new RuntimeException( 'Could not safely publish peer archive without overwriting another file.' );
		}
		self::publish_manifest( $destination, $remote['bytes'], $remote['sha256'] );
		$result = Trasloco_Integrity::verify( $destination );
		$final_digest = isset( $result['sha256'] ) ? $result['sha256'] : false;
		if ( $result['status'] !== 'verified' || ! is_string( $final_digest ) || ! hash_equals( $remote['sha256'], $final_digest ) ) {
			throw new RuntimeException( 'Final archive integrity verification failed.' );
		}
		@unlink( $payload );
		@unlink( $metadata );
		@rmdir( $folder );
		return $destination;
	}
}
