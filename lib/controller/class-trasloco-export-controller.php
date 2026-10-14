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

class Trasloco_Export_Controller {

	public static function index() {
		// Forget the status of the previous run, or the progress window shows it and stops
		delete_option( TRASLOCO_STATUS );

		Trasloco_Template::render( 'export/index' );
	}

	public static function export( $params = array() ) {
		global $wp_filter;

		trasloco_setup_environment();

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( array_merge( $_GET, $_POST ) );
		}

		// Set priority
		$priority = 5;
		if ( isset( $params['priority'] ) ) {
			$priority = (int) $params['priority'];
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		try {
			// Ensure that unauthorized people cannot access export action
			trasloco_verify_operation_token( $secret_key, 'export' );
		} catch ( Trasloco_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		// The browser resumes one export over multiple AJAX requests. Keep the
		// same owner lease in its storage identifier until the final stage.
		if ( empty( $params['storage'] ) ) {
			$params['storage'] = trasloco_storage_folder();
		}
		if ( ! is_string( $params['storage'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $params['storage'] ) ) {
			self::respond_with_export_error( __( 'The export could not start.', TRASLOCO_PLUGIN_NAME ),
				__( 'The export storage identifier is invalid. Refresh the page and try again.', TRASLOCO_PLUGIN_NAME ) );
		}
		$lock_owner = hash( 'sha256', 'trasloco-interactive-export:' . $params['storage'] );
		try {
			$lock = Trasloco_Export_Lock::acquire( $lock_owner );
		} catch ( Throwable $e ) {
			self::respond_with_export_error( __( 'The export could not start.', TRASLOCO_PLUGIN_NAME ),
				__( 'The export lock is unavailable. Check the private backup directory and try again.', TRASLOCO_PLUGIN_NAME ) );
		}
		if ( $lock === false ) {
			self::respond_with_export_error( __( 'Another export is already running.', TRASLOCO_PLUGIN_NAME ),
				__( 'Wait for it to finish before starting another export.', TRASLOCO_PLUGIN_NAME ) );
		}
		$GLOBALS['trasloco_active_export_lock'] = array(
			'handle' => $lock, 'owner' => $lock_owner, 'lease_seconds' => Trasloco_Export_Lock::DEFAULT_LEASE_SECONDS,
			'last_renew' => time(),
		);

		// Get hook
		if ( isset( $wp_filter['trasloco_export'] ) && ( $filters = $wp_filter['trasloco_export'] ) ) {
			// WordPress 4.7 introduces new class for working with filters/actions called WP_Hook
			// which adds another level of abstraction and we need to address it.
			if ( isset( $filters->callbacks ) ) {
				$filters = $filters->callbacks;
			}

			ksort( $filters );

			// Loop over filters
			while ( $hooks = current( $filters ) ) {
				if ( $priority === key( $filters ) ) {
					foreach ( $hooks as $hook ) {
						try {

							// Run function hook
							$params = call_user_func_array( $hook['function'], array( $params ) );

							// Log request
							Trasloco_Log::export( $params );

						} catch ( Exception $e ) {
							Trasloco_Status::error( __( 'The export stopped', TRASLOCO_PLUGIN_NAME ), trasloco_error_message( $e, 'export' ) );
							Trasloco_Notification::error( __( 'The export stopped', TRASLOCO_PLUGIN_NAME ), $e->getMessage() );
							// The storage parameter may be the invalid part of the request
							try {
								Trasloco_Directory::delete( trasloco_storage_path( $params ) );
							} catch ( Exception $ignored ) {
							}
							Trasloco_Export_Lock::release( $lock, $lock_owner );
							unset( $GLOBALS['trasloco_active_export_lock'] );
							$lock = false;
							exit;
						}
					}

					// Set completed
					$completed = true;
					if ( isset( $params['completed'] ) ) {
						$completed = (bool) $params['completed'];
					}

					// Do request
					if ( $completed === false || ( $next = next( $filters ) ) && ( $params['priority'] = key( $filters ) ) ) {
						if ( isset( $params['trasloco_manual_export'] ) ) {
							unset( $GLOBALS['trasloco_active_export_lock'] );
							Trasloco_Export_Lock::suspend( $lock, $lock_owner );
							$lock = false;
							echo json_encode( $params );
							exit;
						}

						Trasloco_Export_Lock::suspend( $lock, $lock_owner );
						unset( $GLOBALS['trasloco_active_export_lock'] );
						$lock = false;
						wp_remote_post( apply_filters( 'trasloco_http_export_url', admin_url( 'admin-ajax.php?action=trasloco_export' ) ), array(
							'timeout'   => apply_filters( 'trasloco_http_export_timeout', 5 ),
							'blocking'  => apply_filters( 'trasloco_http_export_blocking', false ),
							'sslverify' => apply_filters( 'trasloco_http_export_sslverify', true ),
							'headers'   => apply_filters( 'trasloco_http_export_headers', array() ),
							'body'      => apply_filters( 'trasloco_http_export_body', $params ),
						) );
						exit;
					}
				}

				next( $filters );
			}
		}
		if ( is_resource( $lock ) ) {
			Trasloco_Export_Lock::release( $lock, $lock_owner );
			unset( $GLOBALS['trasloco_active_export_lock'] );
		}
	}

	private static function respond_with_export_error( $title, $message ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		}
		echo wp_json_encode( array( 'error' => true, 'title' => $title, 'message' => $message ) );
		exit;
	}


	public static function http_export_headers( $headers = array() ) {
		if ( ( $user = get_option( TRASLOCO_AUTH_USER ) ) && ( $password = trasloco_get_http_password() ) ) {
			if ( ( $hash = base64_encode( sprintf( '%s:%s', $user, $password ) ) ) ) {
				$headers['Authorization'] = sprintf( 'Basic %s', $hash );
			}
		}

		return $headers;
	}
}
