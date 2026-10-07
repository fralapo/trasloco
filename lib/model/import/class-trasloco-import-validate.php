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

class Trasloco_Import_Validate {

	public static function execute( $params ) {

		// Verify file if size > 2GB and PHP = 32-bit
		if ( ! trasloco_is_filesize_supported( trasloco_archive_path( $params ) ) ) {
			throw new Trasloco_Import_Exception(
				__( 'Your PHP is 32-bit. To import this file, switch to 64-bit PHP and try again.', TRASLOCO_PLUGIN_NAME )
			);
		}

		// Set archive bytes offset
		if ( isset( $params['archive_bytes_offset'] ) ) {
			$archive_bytes_offset = (int) $params['archive_bytes_offset'];
		} else {
			$archive_bytes_offset = 0;
		}

		// Set file bytes offset
		if ( isset( $params['file_bytes_offset'] ) ) {
			$file_bytes_offset = (int) $params['file_bytes_offset'];
		} else {
			$file_bytes_offset = 0;
		}

		// Get total archive size
		if ( isset( $params['total_archive_size'] ) ) {
			$total_archive_size = (int) $params['total_archive_size'];
		} else {
			$total_archive_size = trasloco_archive_bytes( $params );
		}

		// What percent of archive have we processed?
		$progress = (int) min( ( $archive_bytes_offset / $total_archive_size ) * 100, 100 );

		// Set progress
		Trasloco_Status::info( sprintf( __( 'Unpacking archive...<br />%d%% complete', TRASLOCO_PLUGIN_NAME ), $progress ) );

		// Open the archive file for reading
		$archive = new Trasloco_Extractor( trasloco_archive_path( $params ) );

		// Set the file pointer to the one that we have saved
		$archive->set_file_pointer( $archive_bytes_offset );

		// Validate the archive file consistency
		if ( ! $archive->is_valid() ) {
			throw new Trasloco_Import_Exception(
				__( 'The archive file is corrupted. Export the site again and upload the new file.', TRASLOCO_PLUGIN_NAME )
			);
		}

		$allowed_size = apply_filters( 'trasloco_max_file_size', TRASLOCO_MAX_FILE_SIZE );

		// Let's check the size of the file to make sure it is less than the maximum allowed
		if ( ( $allowed_size > 0 ) && ( $total_archive_size > $allowed_size ) ) {
			throw new Trasloco_Import_Exception(
				sprintf(
					__( 'The file is larger than the upload limit of <strong>%s</strong>.', TRASLOCO_PLUGIN_NAME ),
					size_format( $allowed_size )
				)
			);
		}

		// Flag to hold if file data has been processed
		$completed = true;

		if ( $archive->has_not_reached_eof() ) {
			$file_bytes_written = 0;

			// Unpack package.json, multisite.json and database.sql files
			if ( ( $completed = $archive->extract_by_files_array( trasloco_storage_path( $params ), array( TRASLOCO_PACKAGE_NAME, TRASLOCO_MULTISITE_NAME, TRASLOCO_DATABASE_NAME ), array(), $file_bytes_written, $file_bytes_offset ) ) ) {
				$file_bytes_offset = 0;
			}

			// Get archive bytes offset
			$archive_bytes_offset = $archive->get_file_pointer();
		}

		// End of the archive?
		if ( $archive->has_reached_eof() ) {

			// Check package.json file
			if ( false === is_file( trasloco_package_path( $params ) ) ) {
				throw new Trasloco_Import_Exception(
					__( 'This is not a valid <strong>.wpress</strong> export: the package.json file is missing.', TRASLOCO_PLUGIN_NAME )
				);
			}

			// Set progress
			Trasloco_Status::info( __( 'Done unpacking archive.', TRASLOCO_PLUGIN_NAME ) );

			// Unset archive bytes offset
			unset( $params['archive_bytes_offset'] );

			// Unset file bytes offset
			unset( $params['file_bytes_offset'] );

			// Unset total archive size
			unset( $params['total_archive_size'] );

			// Unset completed flag
			unset( $params['completed'] );

		} else {

			// What percent of archive have we processed?
			$progress = (int) min( ( $archive_bytes_offset / $total_archive_size ) * 100, 100 );

			// Set progress
			Trasloco_Status::info( sprintf( __( 'Unpacking archive...<br />%d%% complete', TRASLOCO_PLUGIN_NAME ), $progress ) );

			// Set archive bytes offset
			$params['archive_bytes_offset'] = $archive_bytes_offset;

			// Set file bytes offset
			$params['file_bytes_offset'] = $file_bytes_offset;

			// Set total archive size
			$params['total_archive_size'] = $total_archive_size;

			// Set completed flag
			$params['completed'] = $completed;
		}

		// Close the archive file
		$archive->close();

		return $params;
	}
}
