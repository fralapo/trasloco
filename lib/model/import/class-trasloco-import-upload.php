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

class Trasloco_Import_Upload {

	private static function validate() {
		// Chunk sent as base64 in the request body (firewalls block binary uploads that contain PHP code)
		if ( isset( $_POST['upload-chunk'] ) ) {
			return;
		}

		if ( ! array_key_exists( 'upload-file', $_FILES ) || ! is_array( $_FILES['upload-file'] ) ) {
			throw new Trasloco_Import_Retry_Exception(
				__( 'Missing upload file.', TRASLOCO_PLUGIN_NAME ),
				400
			);
		}

		if ( ! array_key_exists( 'error', $_FILES['upload-file'] ) ) {
			throw new Trasloco_Import_Retry_Exception(
				__( 'Missing error key in upload file.', TRASLOCO_PLUGIN_NAME ),
				400
			);
		}

		if ( ! array_key_exists( 'tmp_name', $_FILES['upload-file'] ) ) {
			throw new Trasloco_Import_Retry_Exception(
				__( 'Missing tmp_name in upload file.', TRASLOCO_PLUGIN_NAME ),
				400
			);
		}
	}

	public static function execute( $params ) {
		self::validate();

		// Write at the given offset, so a retried chunk never duplicates data
		if ( isset( $_POST['upload-chunk'] ) ) {
			$data = base64_decode( wp_unslash( $_POST['upload-chunk'] ), true );
			if ( $data === false ) {
				throw new Trasloco_Import_Retry_Exception( __( 'Invalid upload chunk.', TRASLOCO_PLUGIN_NAME ), 400 );
			}

			$archive = trasloco_archive_path( $params );
			wp_mkdir_p( dirname( $archive ) );
			$handle = fopen( $archive, 'c+b' );
			if ( ! $handle ) {
				throw new Trasloco_Import_Retry_Exception( __( 'Unable to open the archive for writing.', TRASLOCO_PLUGIN_NAME ), 400 );
			}

			// The offset must point inside the file or at its end: no gaps, no sparse files
			$offset = isset( $_POST['upload-offset'] ) ? (int) $_POST['upload-offset'] : 0;
			$stat   = fstat( $handle );
			if ( $offset < 0 || $offset > $stat['size'] || fseek( $handle, $offset ) !== 0 || fwrite( $handle, $data ) !== strlen( $data ) ) {
				fclose( $handle );
				throw new Trasloco_Import_Retry_Exception( __( 'Invalid upload chunk.', TRASLOCO_PLUGIN_NAME ), 400 );
			}
			fclose( $handle );

			echo json_encode( array( 'errors' => array() ) );
			exit;
		}

		$error   = $_FILES['upload-file']['error'];
		$upload  = $_FILES['upload-file']['tmp_name'];
		$archive = trasloco_archive_path( $params );

		switch ( $error ) {
			case UPLOAD_ERR_OK:
				try {
					trasloco_copy( $upload, $archive );
					trasloco_unlink( $upload );
				} catch ( Exception $e ) {
					throw new Trasloco_Import_Retry_Exception(
						sprintf(
							__( 'Unable to upload the file because %s', TRASLOCO_PLUGIN_NAME ),
							$e->getMessage()
						),
						400
					);
				}
				break;
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
			case UPLOAD_ERR_PARTIAL:
			case UPLOAD_ERR_NO_FILE:
				// File is too large, reduce the size and try again
				throw new Trasloco_Import_Retry_Exception(
					__( 'The file is too large, retrying with smaller size.', TRASLOCO_PLUGIN_NAME ),
					413
				);
			case UPLOAD_ERR_NO_TMP_DIR:
				throw new Trasloco_Import_Retry_Exception(
					__( 'Missing a temporary folder.', TRASLOCO_PLUGIN_NAME ),
					400
				);
			case UPLOAD_ERR_CANT_WRITE:
				throw new Trasloco_Import_Retry_Exception(
					__( 'Failed to write file to disk.', TRASLOCO_PLUGIN_NAME ),
					400
				);
			case UPLOAD_ERR_EXTENSION:
				throw new Trasloco_Import_Retry_Exception(
					__( 'A PHP extension stopped the file upload.', TRASLOCO_PLUGIN_NAME ),
					400
				);
			default:
				throw new Trasloco_Import_Retry_Exception(
					sprintf(
						__( 'Unrecognized error %s during upload.', TRASLOCO_PLUGIN_NAME ),
						$error
					),
					400
				);
		}

		echo json_encode( array( 'errors' => array() ) );
		exit;
	}
}
