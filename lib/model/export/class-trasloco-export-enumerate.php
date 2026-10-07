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

class Trasloco_Export_Enumerate {

	public static function execute( $params ) {

		// Get total files count
		if ( isset( $params['total_files_count'] ) ) {
			$total_files_count = (int) $params['total_files_count'];
		} else {
			$total_files_count = 0;
		}

		// Get total files size
		if ( isset( $params['total_files_size'] ) ) {
			$total_files_size = (int) $params['total_files_size'];
		} else {
			$total_files_size = 0;
		}

		// Set progress
		Trasloco_Status::info( __( 'Listing the files to copy (media, themes, plugins)...', TRASLOCO_PLUGIN_NAME ) );

		// Set exclude filters
		$exclude_filters = trasloco_content_filters();

		// Exclude cache
		if ( isset( $params['options']['no_cache'] ) ) {
			$exclude_filters[] = 'cache';
		}

		// Exclude themes
		if ( isset( $params['options']['no_themes'] ) ) {
			$exclude_filters[] = 'themes';
		} else {
			$inactive_themes = array();

			// Exclude inactive themes
			if ( isset( $params['options']['no_inactive_themes'] ) ) {
				foreach ( wp_get_themes() as $theme => $info ) {
					// Exclude current parent and child themes
					if ( ! in_array( $theme, array( get_template(), get_stylesheet() ) ) ) {
						$inactive_themes[] = 'themes' . DIRECTORY_SEPARATOR . $theme;
					}
				}
			}

			// Set exclude filters
			$exclude_filters = array_merge( $exclude_filters, $inactive_themes );
		}

		// Exclude must-use plugins
		if ( isset( $params['options']['no_muplugins'] ) ) {
			$exclude_filters = array_merge( $exclude_filters, array( 'mu-plugins' ) );
		}

		// Exclude plugins
		if ( isset( $params['options']['no_plugins'] ) ) {
			$exclude_filters = array_merge( $exclude_filters, array( 'plugins' ) );
		} else {
			$inactive_plugins = array();

			// Exclude inactive plugins
			if ( isset( $params['options']['no_inactive_plugins'] ) ) {
				foreach ( get_plugins() as $plugin => $info ) {
					if ( is_plugin_inactive( $plugin ) ) {
						$inactive_plugins[] = 'plugins' . DIRECTORY_SEPARATOR .
							( ( dirname( $plugin ) === '.' ) ? basename( $plugin ) : dirname( $plugin ) );
					}
				}
			}

			// Set exclude filters
			$exclude_filters = array_merge( $exclude_filters, trasloco_plugin_filters( $inactive_plugins ) );
		}

		// Exclude media
		if ( isset( $params['options']['no_media'] ) ) {
			$exclude_filters = array_merge( $exclude_filters, array( 'uploads', 'blogs.dir' ) );
		}

		// Create map file
		$filemap = trasloco_open( trasloco_filemap_path( $params ), 'w' );

		// Iterate over content directory
		$iterator = new Trasloco_Recursive_Directory_Iterator( WP_CONTENT_DIR );

		// Exclude new line file names
		$iterator = new Trasloco_Recursive_Newline_Filter( $iterator );

		// Exclude uploads, plugins or themes
		$iterator = new Trasloco_Recursive_Exclude_Filter( $iterator, apply_filters( 'trasloco_exclude_content_from_export', $exclude_filters ) );

		// Recursively iterate over content directory
		$iterator = new Trasloco_Recursive_Iterator_Iterator( $iterator, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );

		// Write path line
		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				if ( trasloco_write( $filemap, $iterator->getSubPathName() . PHP_EOL ) ) {
					$total_files_count++;

					// Add current file size
					$total_files_size += $iterator->getSize();
				}
			}
		}

		// Set progress
		Trasloco_Status::info( __( 'File list ready.', TRASLOCO_PLUGIN_NAME ) );

		// Set total files count
		$params['total_files_count'] = $total_files_count;

		// Set total files size
		$params['total_files_size'] = $total_files_size;

		// Close the filemap file
		trasloco_close( $filemap );

		return $params;
	}
}
