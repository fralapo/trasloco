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

class Trasloco_Import_Confirm {

	public static function execute( $params ) {

		$messages = array();

		// Read package.json file
		$handle = trasloco_open( trasloco_package_path( $params ), 'r' );

		// Parse package.json file
		$package = trasloco_read( $handle, filesize( trasloco_package_path( $params ) ) );
		$package = json_decode( $package, true );

		// Close handle
		trasloco_close( $handle );

		// Set message
		$messages[] = __(
			'The import process will overwrite your website including the database, media, plugins, and themes. ' .
			'Please ensure that you have a backup of your data before proceeding to the next step.',
			TRASLOCO_PLUGIN_NAME
		);

		// Check compatibility of PHP versions
		if ( isset( $package['PHP']['Version'] ) ) {
			if ( version_compare( $package['PHP']['Version'], '7.0.0', '<' ) && version_compare( PHP_VERSION, '7.0.0', '>=' ) ) {
				$messages[] = __( '<i class="trasloco-import-info">This backup comes from PHP 5 and this site runs PHP 7 or later. The import could fail.</i>', TRASLOCO_PLUGIN_NAME );
			}
		}

		// Set progress
		Trasloco_Status::confirm( implode( $messages ) );
		exit;
	}
}
