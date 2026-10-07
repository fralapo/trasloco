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

class Trasloco_Import_Blogs {

	public static function execute( $params ) {

		// Set progress
		Trasloco_Status::info( __( 'Reading the site details from the file...', TRASLOCO_PLUGIN_NAME ) );

		$blogs = array();

		// Check multisite.json file
		if ( true === is_file( trasloco_multisite_path( $params ) ) ) {

			// Read multisite.json file
			$handle = trasloco_open( trasloco_multisite_path( $params ), 'r' );

			// Parse multisite.json file
			$multisite = trasloco_read( $handle, filesize( trasloco_multisite_path( $params ) ) );
			$multisite = json_decode( $multisite, true );

			// Close handle
			trasloco_close( $handle );

			// Validate
			if ( empty( $multisite['Network'] ) ) {
				if ( isset( $multisite['Sites'] ) && ( $sites = $multisite['Sites'] ) ) {
					if ( count( $sites ) === 1 && ( $subsite = current( $sites ) ) ) {

						// Set internal Site URL (backward compatibility)
						if ( empty( $subsite['InternalSiteURL'] ) ) {
							$subsite['InternalSiteURL'] = null;
						}

						// Set internal Home URL (backward compatibility)
						if ( empty( $subsite['InternalHomeURL'] ) ) {
							$subsite['InternalHomeURL'] = null;
						}

						// Set active plugins (backward compatibility)
						if ( empty( $subsite['Plugins'] ) ) {
							$subsite['Plugins'] = array();
						}

						// Set active template (backward compatibility)
						if ( empty( $subsite['Template'] ) ) {
							$subsite['Template'] = null;
						}

						// Set active stylesheet (backward compatibility)
						if ( empty( $subsite['Stylesheet'] ) ) {
							$subsite['Stylesheet'] = null;
						}

						// Set blog items
						$blogs[] = array(
							'Old' => array(
								'BlogID'          => $subsite['BlogID'],
								'SiteURL'         => $subsite['SiteURL'],
								'HomeURL'         => $subsite['HomeURL'],
								'InternalSiteURL' => $subsite['InternalSiteURL'],
								'InternalHomeURL' => $subsite['InternalHomeURL'],
								'Plugins'         => $subsite['Plugins'],
								'Template'        => $subsite['Template'],
								'Stylesheet'      => $subsite['Stylesheet'],
							),
							'New' => array(
								'BlogID'          => null,
								'SiteURL'         => site_url(),
								'HomeURL'         => home_url(),
								'InternalSiteURL' => site_url(),
								'InternalHomeURL' => home_url(),
								'Plugins'         => $subsite['Plugins'],
								'Template'        => $subsite['Template'],
								'Stylesheet'      => $subsite['Stylesheet'],
							),
						);
					} else {
						throw new Trasloco_Import_Exception(
							__( 'This file comes from a WordPress Multisite network (several sites managed together). Trasloco can import only single sites.', TRASLOCO_PLUGIN_NAME )
						);
					}
				} else {
					throw new Trasloco_Import_Exception(
						__( 'This file does not contain a site. Export the old site again and use the new file.', TRASLOCO_PLUGIN_NAME )
					);
				}
			} else {
				throw new Trasloco_Import_Exception(
					__( 'This file comes from a WordPress Multisite network (several sites managed together). Trasloco can import only single sites.', TRASLOCO_PLUGIN_NAME )
				);
			}
		}

		// Write blogs.json file
		$handle = trasloco_open( trasloco_blogs_path( $params ), 'w' );
		trasloco_write( $handle, json_encode( $blogs ) );
		trasloco_close( $handle );

		// Set progress
		Trasloco_Status::info( __( 'Site details read.', TRASLOCO_PLUGIN_NAME ) );

		return $params;
	}
}
