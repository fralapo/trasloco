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

class Trasloco_Export_Config {

	public static function execute( $params ) {
		global $wp_version, $wpdb;

		// Set progress
		Trasloco_Status::info( __( 'Noting the site address, theme and active plugins...', TRASLOCO_PLUGIN_NAME ) );

		// Get options
		$options = wp_load_alloptions();

		// Get database client
		if ( empty( $wpdb->use_mysqli ) ) {
			$mysql = new Trasloco_Database_Mysql( $wpdb );
		} else {
			$mysql = new Trasloco_Database_Mysqli( $wpdb );
		}

		$config = array();

		// Set site URL
		$config['SiteURL'] = site_url();

		// Set home URL
		$config['HomeURL'] = home_url();

		// Set internal site URL
		if ( isset( $options['siteurl'] ) && ( untrailingslashit( $options['siteurl'] ) !== site_url() ) ) {
			$config['InternalSiteURL'] = untrailingslashit( $options['siteurl'] );
		}

		// Set internal home URL
		if ( isset( $options['home'] ) && ( untrailingslashit( $options['home'] ) !== home_url() ) ) {
			$config['InternalHomeURL'] = untrailingslashit( $options['home'] );
		}

		// Set replace old and new values
		if ( isset( $params['options']['replace'] ) && ( $replace = $params['options']['replace'] ) ) {
			for ( $i = 0; $i < count( $replace['old_value'] ); $i++ ) {
				if ( ! empty( $replace['old_value'][ $i ] ) && ! empty( $replace['new_value'][ $i ] ) ) {
					$config['Replace']['OldValues'][] = $replace['old_value'][ $i ];
					$config['Replace']['NewValues'][] = $replace['new_value'][ $i ];
				}
			}
		}

		// Set no spam comments
		if ( isset( $params['options']['no_spam_comments'] ) ) {
			$config['NoSpamComments'] = true;
		}

		// Set no post revisions
		if ( isset( $params['options']['no_post_revisions'] ) ) {
			$config['NoPostRevisions'] = true;
		}

		// Set no media
		if ( isset( $params['options']['no_media'] ) ) {
			$config['NoMedia'] = true;
		}

		// Set no themes
		if ( isset( $params['options']['no_themes'] ) ) {
			$config['NoThemes'] = true;
		}

		// Set no inactive themes
		if ( isset( $params['options']['no_inactive_themes'] ) ) {
			$config['NoInactiveThemes'] = true;
		}

		// Set no must-use plugins
		if ( isset( $params['options']['no_muplugins'] ) ) {
			$config['NoMustUsePlugins'] = true;
		}

		// Set no plugins
		if ( isset( $params['options']['no_plugins'] ) ) {
			$config['NoPlugins'] = true;
		}

		// Set no inactive plugins
		if ( isset( $params['options']['no_inactive_plugins'] ) ) {
			$config['NoInactivePlugins'] = true;
		}

		// Set no cache
		if ( isset( $params['options']['no_cache'] ) ) {
			$config['NoCache'] = true;
		}

		// Set no database
		if ( isset( $params['options']['no_database'] ) ) {
			$config['NoDatabase'] = true;
		}

		// Set no email replace
		if ( isset( $params['options']['no_email_replace'] ) ) {
			$config['NoEmailReplace'] = true;
		}

		// Set plugin version
		$config['Plugin'] = array( 'Version' => TRASLOCO_VERSION );

		// Set WordPress version and content
		$config['WordPress'] = array( 'Version' => $wp_version, 'Content' => WP_CONTENT_DIR );

		// Set database version
		$config['Database'] = array( 'Version' => $mysql->version() );

		// Set PHP version
		$config['PHP'] = array( 'Version' => PHP_VERSION );

		// Set active plugins
		$config['Plugins'] = array_values( array_diff( trasloco_active_plugins(), trasloco_active_own_plugins() ) );

		// Set active template
		$config['Template'] = trasloco_active_template();

		// Set active stylesheet
		$config['Stylesheet'] = trasloco_active_stylesheet();

		// Save package.json file
		$handle = trasloco_open( trasloco_package_path( $params ), 'w' );
		trasloco_write( $handle, json_encode( $config ) );
		trasloco_close( $handle );

		// Set progress
		Trasloco_Status::info( __( 'Site details noted.', TRASLOCO_PLUGIN_NAME ) );

		return $params;
	}
}
