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

class Trasloco_Import_Done {

	public static function execute( $params ) {

		// Check multisite.json file
		if ( true === is_file( trasloco_multisite_path( $params ) ) ) {
			// Read multisite.json file
			$handle = trasloco_open( trasloco_multisite_path( $params ), 'r' );

			// Parse multisite.json file
			$multisite = trasloco_read( $handle, filesize( trasloco_multisite_path( $params ) ) );
			$multisite = json_decode( $multisite, true );

			// Close handle
			trasloco_close( $handle );

			// Activate WordPress plugins
			if ( isset( $multisite['Plugins'] ) && ( $plugins = $multisite['Plugins'] ) ) {
				trasloco_activate_plugins( $plugins );
			}

			// Deactivate WordPress SSL plugins
			if ( ! is_ssl() ) {
				trasloco_deactivate_plugins( array(
					trasloco_discover_plugin_basename( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
					trasloco_discover_plugin_basename( 'wordpress-https/wordpress-https.php' ),
					trasloco_discover_plugin_basename( 'wp-force-ssl/wp-force-ssl.php' ),
				) );
			}

			// Deactivate WordPress plugins
			trasloco_deactivate_plugins( array(
				trasloco_discover_plugin_basename( 'invisible-recaptcha/invisible-recaptcha.php' ),
				trasloco_discover_plugin_basename( 'wps-hide-login/wps-hide-login.php' ),
				trasloco_discover_plugin_basename( 'hide-my-wp/index.php' ),
				trasloco_discover_plugin_basename( 'hide-my-wordpress/index.php' ),
				trasloco_discover_plugin_basename( 'mycustomwidget/my_custom_widget.php' ),
				trasloco_discover_plugin_basename( 'lockdown-wp-admin/lockdown-wp-admin.php' ),
				trasloco_discover_plugin_basename( 'rename-wp-login/rename-wp-login.php' ),
			) );

			// Deactivate Jetpack modules
			trasloco_deactivate_jetpack_modules( array(
				'photon',
				'sso',
			) );

		} else {

			// Check package.json file
			if ( true === is_file( trasloco_package_path( $params ) ) ) {

				// Read package.json file
				$handle = trasloco_open( trasloco_package_path( $params ), 'r' );

				// Parse package.json file
				$package = trasloco_read( $handle, filesize( trasloco_package_path( $params ) ) );
				$package = json_decode( $package, true );

				// Close handle
				trasloco_close( $handle );

				// Activate WordPress plugins
				if ( isset( $package['Plugins'] ) && ( $plugins = $package['Plugins'] ) ) {
					trasloco_activate_plugins( $plugins );
				}

				// Activate WordPress template
				if ( isset( $package['Template'] ) && ( $template = $package['Template'] ) ) {
					trasloco_activate_template( $template );
				}

				// Activate WordPress stylesheet
				if ( isset( $package['Stylesheet'] ) && ( $stylesheet = $package['Stylesheet'] ) ) {
					trasloco_activate_stylesheet( $stylesheet );
				}

				// Deactivate WordPress SSL plugins
				if ( ! is_ssl() ) {
					trasloco_deactivate_plugins( array(
						trasloco_discover_plugin_basename( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
						trasloco_discover_plugin_basename( 'wordpress-https/wordpress-https.php' ),
						trasloco_discover_plugin_basename( 'wp-force-ssl/wp-force-ssl.php' ),
					) );
				}

				// Deactivate WordPress plugins
				trasloco_deactivate_plugins( array(
					trasloco_discover_plugin_basename( 'invisible-recaptcha/invisible-recaptcha.php' ),
					trasloco_discover_plugin_basename( 'wps-hide-login/wps-hide-login.php' ),
					trasloco_discover_plugin_basename( 'hide-my-wp/index.php' ),
					trasloco_discover_plugin_basename( 'hide-my-wordpress/index.php' ),
					trasloco_discover_plugin_basename( 'mycustomwidget/my_custom_widget.php' ),
					trasloco_discover_plugin_basename( 'lockdown-wp-admin/lockdown-wp-admin.php' ),
					trasloco_discover_plugin_basename( 'rename-wp-login/rename-wp-login.php' ),
				) );

				// Deactivate Jetpack modules
				trasloco_deactivate_jetpack_modules( array(
					'photon',
					'sso',
				) );
			}
		}

		// Check blogs.json file
		if ( true === is_file( trasloco_blogs_path( $params ) ) ) {
			// Read blogs.json file
			$handle = trasloco_open( trasloco_blogs_path( $params ), 'r' );

			// Parse blogs.json file
			$blogs = trasloco_read( $handle, filesize( trasloco_blogs_path( $params ) ) );
			$blogs = json_decode( $blogs, true );

			// Close handle
			trasloco_close( $handle );

			// Loop over blogs
			foreach ( $blogs as $blog ) {

				// Activate WordPress plugins
				if ( isset( $blog['New']['Plugins'] ) && ( $plugins = $blog['New']['Plugins'] ) ) {
					trasloco_activate_plugins( $plugins );
				}

				// Activate WordPress template
				if ( isset( $blog['New']['Template'] ) && ( $template = $blog['New']['Template'] ) ) {
					trasloco_activate_template( $template );
				}

				// Activate WordPress stylesheet
				if ( isset( $blog['New']['Stylesheet'] ) && ( $stylesheet = $blog['New']['Stylesheet'] ) ) {
					trasloco_activate_stylesheet( $stylesheet );
				}

				// Deactivate WordPress SSL plugins
				if ( ! is_ssl() ) {
					trasloco_deactivate_plugins( array(
						trasloco_discover_plugin_basename( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ),
						trasloco_discover_plugin_basename( 'wordpress-https/wordpress-https.php' ),
						trasloco_discover_plugin_basename( 'wp-force-ssl/wp-force-ssl.php' ),
					) );
				}

				// Deactivate WordPress plugins
				trasloco_deactivate_plugins( array(
					trasloco_discover_plugin_basename( 'invisible-recaptcha/invisible-recaptcha.php' ),
					trasloco_discover_plugin_basename( 'wps-hide-login/wps-hide-login.php' ),
					trasloco_discover_plugin_basename( 'hide-my-wp/index.php' ),
					trasloco_discover_plugin_basename( 'hide-my-wordpress/index.php' ),
					trasloco_discover_plugin_basename( 'mycustomwidget/my_custom_widget.php' ),
					trasloco_discover_plugin_basename( 'lockdown-wp-admin/lockdown-wp-admin.php' ),
					trasloco_discover_plugin_basename( 'rename-wp-login/rename-wp-login.php' ),
				) );

				// Deactivate Jetpack modules
				trasloco_deactivate_jetpack_modules( array(
					'photon',
					'sso',
				) );
			}
		}

		// Database commands that failed: say so, instead of a plain success
		$warning = '';
		$errors  = @file( trasloco_storage_path( $params ) . DIRECTORY_SEPARATOR . 'database-errors.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( $errors ) {
			$warning = sprintf(
				'<p class="tr-para"><strong>%s</strong></p><span class="tr-para tr-tech">%s</span>',
				sprintf( __( 'Warning: %d database commands failed, so part of the content or settings may be missing. Check the site carefully; if something is missing, send the technical details below to whoever manages the server.', TRASLOCO_PLUGIN_NAME ), count( $errors ) ),
				implode( '<br />', array_map( 'esc_html', array_slice( $errors, 0, 3 ) ) )
			);
		}

		// Set progress
		Trasloco_Status::done(
			__(
				'The site has been imported',
				TRASLOCO_PLUGIN_NAME
			),
			$warning . sprintf(
				__(
					'<p class="tr-para">Four things are left to do:</p>' .
					'<ol class="tr-done-steps">' .
					'<li>Log in again with the username and password of the old site. You have been logged out because the users were replaced.</li>' .
					'<li><a href="%s" target="_blank">Open Settings → Permalinks</a> and click Save Changes twice. This rebuilds the page addresses, so that links do not end on a “Page not found” error.</li>' .
					'<li>Some plugins were switched off so that you can log in: plugins that hide or rename the login page, reCAPTCHA, plugins that force https:// when this site has no security certificate, and the Jetpack image CDN and single sign-on. Open Plugins and switch back on the ones you need.</li>' .
					'<li>Open the site and check a few pages, images and forms.</li>' .
					'</ol>',
					TRASLOCO_PLUGIN_NAME
				),
				admin_url( 'options-permalink.php#submit' )
			)
		);

		return $params;
	}
}
