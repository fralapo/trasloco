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
			'<strong class="tr-para">Replace this site with the content of the file?</strong>' .
			'<span class="tr-para">Everything on this site will be replaced: posts, pages, media, users, themes, plugins and settings. This cannot be undone.</span>' .
			'<span class="tr-para">Continue only if you have a backup of this site. Afterwards you log in with the username and password saved in the file, those of the site the file was made from.</span>',
			TRASLOCO_PLUGIN_NAME
		);

		// Check compatibility of PHP versions
		if ( isset( $package['PHP']['Version'] ) ) {
			if ( version_compare( $package['PHP']['Version'], '7.0.0', '<' ) && version_compare( PHP_VERSION, '7.0.0', '>=' ) ) {
				$messages[] = __( '<i class="trasloco-import-info">The old site ran on PHP 5, an old version of the software WordPress runs on. This server uses PHP 7 or later, so some old themes or plugins may not work here.</i>', TRASLOCO_PLUGIN_NAME );
			}
		}

		// Set progress
		Trasloco_Status::confirm( implode( $messages ) );
		exit;
	}
}
