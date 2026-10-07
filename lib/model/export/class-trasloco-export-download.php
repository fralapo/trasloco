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

class Trasloco_Export_Download {

	public static function execute( $params ) {

		// Set progress
		Trasloco_Status::info( __( 'Finishing the export file...', TRASLOCO_PLUGIN_NAME ) );

		// Open the archive file for writing
		$archive = new Trasloco_Compressor( trasloco_archive_path( $params ) );

		// Append EOF block
		$archive->close( true );

		// Rename archive file
		if ( rename( trasloco_archive_path( $params ), trasloco_backup_path( $params ) ) ) {

			$blog_id = null;

			// Get subsite Blog ID
			if ( isset( $params['options']['sites'] ) && ( $sites = $params['options']['sites'] ) ) {
				if ( count( $sites ) === 1 ) {
					$blog_id = array_shift( $sites );
				}
			}

			// Set archive details
			$link = trasloco_backup_url( $params );
			$size = trasloco_backup_size( $params );
			$name = trasloco_site_name( $blog_id );

			// Set progress
			Trasloco_Status::download(
				sprintf(
					__(
						'<a href="%s" class="trasloco-button-green trasloco-emphasize">' .
						'<span>Download the export file of %s</span>' .
						'<em>Size: %s</em>' .
						'</a>',
						TRASLOCO_PLUGIN_NAME
					),
					$link,
					$name,
					$size
				) .
				'<span class="tr-modal-next">' . esc_html__( 'Next: on the new site, open Trasloco → Import and upload this file. A copy of the file also stays in Trasloco → Backups.', TRASLOCO_PLUGIN_NAME ) . '</span>'
			);
		}

		return $params;
	}
}
