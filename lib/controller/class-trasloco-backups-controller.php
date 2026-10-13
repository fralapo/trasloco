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
		// Forget the status of the previous run, or the progress window shows it and stops
		delete_option( TRASLOCO_STATUS );

		$model = new Trasloco_Backups;

		Trasloco_Template::render(
			'backups/index',
			array(
				'backups'  => $model->get_files(),
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
			$path = trasloco_backup_path( array( 'archive' => $archive ) );
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
