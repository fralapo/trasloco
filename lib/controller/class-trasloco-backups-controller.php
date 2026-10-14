<?php
/**
 * Copyright (C) 2014-2018 ServMask Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

class Trasloco_Backups_Controller {

	public static function index() {
		require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-selective-restore-lock.php';
		$selective_restore_active = Trasloco_Selective_Restore_Lock::active();
		// Preserve the progress report if a selective restore is still running.
		if ( ! $selective_restore_active ) {
			delete_option( TRASLOCO_STATUS );
		}

		$model = new Trasloco_Backups;

		Trasloco_Template::render(
			'backups/index',
			array(
				'backups'  => $model->get_files(),
				'selective_restore_active' => $selective_restore_active,
			)
		);
	}


	/** Stream backups only through an authenticated, nonce-protected endpoint. */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', '', array( 'response' => 403 ) );
		}
		$archive = isset( $_GET['archive'] ) && is_string( $_GET['archive'] ) ? wp_unslash( $_GET['archive'] ) : '';
		check_admin_referer( 'trasloco_download_file' );
		try {
			if ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress\.enc$/D', $archive ) &&
				strpos( $archive, '..' ) === false ) {
				$path = trasloco_encrypted_backup_path( $archive );
				require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
				if ( Trasloco_Integrity::verify( $path )['status'] !== 'verified' ) {
					wp_die( 'Encrypted backup verification failed', '', array( 'response' => 409 ) );
				}
			} else {
				$path = trasloco_backup_path( array( 'archive' => $archive ) );
			}
		} catch ( Exception $e ) {
			wp_die( 'Invalid backup', '', array( 'response' => 400 ) );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( 'Backup not found', '', array( 'response' => 404 ) );
		}
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			wp_die( 'Unable to read backup', '', array( 'response' => 500 ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1024 * 1024 );
			if ( $chunk === false ) {
				break;
			}
			echo $chunk;
		}
		fclose( $handle );
		exit;
	}

	/** Encrypt a previously verified backup using the configured secret only. */
	public static function encrypt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'trasloco_encrypt_backup', 'trasloco_encrypt_nonce' );
		$archive = isset( $_POST['archive'] ) && is_string( $_POST['archive'] )
			? sanitize_file_name( wp_unslash( $_POST['archive'] ) ) : '';
		$redirect = admin_url( 'admin.php?page=trasloco_backups' );
		try {
			$passphrase = defined( 'TRASLOCO_ARCHIVE_PASSPHRASE' )
				? TRASLOCO_ARCHIVE_PASSPHRASE : getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
			ignore_user_abort( true );
			$encrypted_name = self::encrypt_archive( $archive, $passphrase );
			$redirect = add_query_arg( array(
				'trasloco_encrypt_status' => 'complete',
				'trasloco_encrypted_archive' => $encrypted_name,
			), $redirect );
		} catch ( Throwable $error ) {
			$redirect = add_query_arg( 'trasloco_encrypt_status', 'error', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Decrypt a verified private archive without accepting the passphrase over HTTP. */
	public static function decrypt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'trasloco_decrypt_backup', 'trasloco_decrypt_nonce' );
		$archive = isset( $_POST['archive'] ) && is_string( $_POST['archive'] )
			? wp_unslash( $_POST['archive'] ) : '';
		$redirect = admin_url( 'admin.php?page=trasloco_backups' );
		try {
			$passphrase = defined( 'TRASLOCO_ARCHIVE_PASSPHRASE' )
				? TRASLOCO_ARCHIVE_PASSPHRASE : getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
			ignore_user_abort( true );
			$decrypted_name = self::decrypt_archive( $archive, $passphrase );
			$redirect = add_query_arg( array(
				'trasloco_decrypt_status' => 'complete',
				'trasloco_decrypted_archive' => $decrypted_name,
			), $redirect );
		} catch ( Throwable $error ) {
			$redirect = add_query_arg( 'trasloco_decrypt_status', 'error', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Decrypt an authenticated .wpress.enc to a new, structurally checked archive. */
	public static function decrypt_archive( $archive, $passphrase ) {
		if ( ! is_string( $archive ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress\.enc$/D', $archive ) ||
			strpos( $archive, '..' ) !== false || ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
			throw new RuntimeException( 'Invalid encrypted archive or encryption configuration.' );
		}
		require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
		$source = trasloco_encrypted_backup_path( $archive );
		clearstatcache( true, $source );
		$source_stat = @lstat( $source );
		if ( ! is_array( $source_stat ) || is_link( $source ) || ! is_file( $source ) || ! is_readable( $source ) ||
			Trasloco_Integrity::verify( $source )['status'] !== 'verified' ) {
			throw new RuntimeException( 'Only a regular encrypted backup with a verified manifest can be decrypted.' );
		}
		clearstatcache( true, $source );
		$verified_stat = @lstat( $source );
		if ( ! self::same_file_identity( $source_stat, $verified_stat ) ) {
			throw new RuntimeException( 'Encrypted backup changed during integrity verification.' );
		}
		$decrypted_name = substr( $archive, 0, -strlen( '.wpress.enc' ) ) . '-decrypted.wpress';
		$target = trasloco_backup_path( array( 'archive' => $decrypted_name ) );
		$manifest = Trasloco_Integrity::manifest_path( $target );
		if ( file_exists( $target ) || is_link( $target ) || file_exists( $manifest ) || is_link( $manifest ) ) {
			throw new RuntimeException( 'Decrypted backup already exists.' );
		}
		@set_time_limit( 0 );
		require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
		require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-offline-recovery.php';
		try {
			Trasloco_Encryption::decrypt( $source, $target, $passphrase );
			Trasloco_Offline_Recovery::inspect( $target );
			clearstatcache( true, $source );
			if ( ! self::same_file_identity( $verified_stat, @lstat( $source ) ) ) {
				throw new RuntimeException( 'Encrypted backup changed during decryption.' );
			}
			Trasloco_Integrity::create_chunked( $target );
			if ( Trasloco_Integrity::verify( $target )['status'] !== 'verified' ) {
				throw new RuntimeException( 'Decrypted archive integrity verification failed.' );
			}
		} catch ( Throwable $error ) {
			@unlink( $target );
			@unlink( $manifest );
			throw $error;
		}
		return basename( $target );
	}

	/** Create a verified encrypted sibling without replacing either file. */
	public static function encrypt_archive( $archive, $passphrase ) {
		if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
			throw new RuntimeException( 'Archive encryption is not configured.' );
		}
		$source = trasloco_backup_path( array( 'archive' => $archive ) );
		require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
		clearstatcache( true, $source );
		$source_stat = @lstat( $source );
		if ( ! is_array( $source_stat ) || is_link( $source ) || ! is_file( $source ) ) {
			throw new RuntimeException( 'Only a regular backup file can be encrypted.' );
		}
		if ( Trasloco_Integrity::verify( $source )['status'] !== 'verified' ) {
			throw new RuntimeException( 'Only a verified backup can be encrypted.' );
		}
		clearstatcache( true, $source );
		$verified_stat = @lstat( $source );
		if ( ! self::same_file_identity( $source_stat, $verified_stat ) ) {
			throw new RuntimeException( 'Backup changed during integrity verification.' );
		}
		$target = trasloco_encrypted_backup_path( $archive . '.enc' );
		$manifest = Trasloco_Integrity::manifest_path( $target );
		if ( file_exists( $target ) || is_link( $target ) || file_exists( $manifest ) || is_link( $manifest ) ) {
			throw new RuntimeException( 'Encrypted backup already exists.' );
		}
		@set_time_limit( 0 );
		require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
		Trasloco_Encryption::encrypt( $source, $target, $passphrase );
		try {
			Trasloco_Integrity::create( $target );
			clearstatcache( true, $source );
			if ( ! self::same_file_identity( $verified_stat, @lstat( $source ) ) ) {
				throw new RuntimeException( 'Backup changed during encryption.' );
			}
		} catch ( Throwable $error ) {
			@unlink( $target );
			@unlink( $manifest );
			throw $error;
		}
		return basename( $target );
	}

	private static function same_file_identity( $expected, $actual ) {
		if ( ! is_array( $expected ) || ! is_array( $actual ) ) {
			return false;
		}
		foreach ( array( 'size', 'mtime', 'ctime', 'ino', 'dev' ) as $field ) {
			if ( ! isset( $expected[ $field ], $actual[ $field ] ) ||
				(int) $expected[ $field ] !== (int) $actual[ $field ] ) {
				return false;
			}
		}
		return true;
	}

	public static function delete( $params = array() ) {
		$errors = array();

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		// Set archive
		$archive = null;
		if ( isset( $params['archive'] ) ) {
			$archive = trim( $params['archive'] );
		}

		try {
			// Ensure that unauthorized people cannot access delete action
			if ( ! current_user_can( 'manage_options' ) ) {
				exit;
			}
			trasloco_verify_operation_token( $secret_key, 'backups' );
		} catch ( Trasloco_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		$model = new Trasloco_Backups;

		try {
			// Delete file
			$model->delete_file( $archive );
		} catch ( Exception $e ) {
			$errors[] = $e->getMessage();
		}

		echo json_encode( array( 'errors' => $errors ) );
		exit;
	}
}
