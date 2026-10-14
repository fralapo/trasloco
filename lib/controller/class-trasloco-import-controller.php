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

class Trasloco_Import_Controller {

	public static function index() {
		// Forget the status of the previous run, or the progress window shows it and stops
		delete_option( TRASLOCO_STATUS );

		Trasloco_Template::render( 'import/index' );
	}

	public static function import( $params = array() ) {
		global $wp_filter;

		trasloco_setup_environment();

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( array_merge( $_GET, $_POST ) );
		}

		// Set priority
		$priority = 10;
		if ( isset( $params['priority'] ) ) {
			$priority = (int) $params['priority'];
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		try {
			// Ensure that unauthorized people cannot access import action
			trasloco_verify_operation_token( $secret_key, 'import' );
		} catch ( Trasloco_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-selective-restore-lock.php';
		if ( ! empty( $params['trasloco_selective_tables'] ) ) {
			$owner = isset( $params['trasloco_selective_owner'] ) ? $params['trasloco_selective_owner'] : '';
			$locked = $priority <= 10
				? Trasloco_Selective_Restore_Lock::acquire( $owner )
				: Trasloco_Selective_Restore_Lock::renew( $owner );
			if ( ! $locked ) {
				status_header( 409 );
				Trasloco_Status::error(
					__( 'The import stopped', TRASLOCO_PLUGIN_NAME ),
					__( 'Another selective database restore is active or its safety lease expired. Check the site and retry only after reviewing recovery status.', TRASLOCO_PLUGIN_NAME )
				);
				echo json_encode( array( 'errors' => array( array( 'code' => 409, 'message' => 'Selective restore lease unavailable.' ) ) ) );
				exit;
			}
		} elseif ( Trasloco_Selective_Restore_Lock::active() ) {
			status_header( 409 );
			Trasloco_Status::error(
				__( 'The import stopped', TRASLOCO_PLUGIN_NAME ),
				__( 'A selective database restore is active. Wait for it to finish before starting another import.', TRASLOCO_PLUGIN_NAME )
			);
			echo json_encode( array( 'errors' => array( array( 'code' => 409, 'message' => 'Selective restore already active.' ) ) ) );
			exit;
		}

		// Selected-table restore deliberately excludes archive files and plugin
		// payloads. The database preflight revalidates the table allowlist before
		// the recovery snapshot or any SQL statements are applied.
		if ( ! empty( $params['trasloco_selective_tables'] ) ) {
			remove_filter( 'trasloco_import', 'Trasloco_Import_Confirm::execute', 100 );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Content::execute', 250 );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Mu_Plugins::execute', 270 );
		}

		// Get hook
		if ( isset( $wp_filter['trasloco_import'] ) && ( $filters = $wp_filter['trasloco_import'] ) ) {
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
							Trasloco_Log::import( $params );

						} catch ( Trasloco_Import_Retry_Exception $e ) {
							status_header( $e->getCode() );
							echo json_encode( array( 'errors' => array( array( 'code' => $e->getCode(), 'message' => $e->getMessage() ) ) ) );
							exit;
						} catch ( Exception $e ) {
							if ( ! empty( $params['trasloco_selective_owner'] ) ) {
								Trasloco_Selective_Restore_Lock::release( $params['trasloco_selective_owner'] );
							}
							Trasloco_Status::error( __( 'The import stopped', TRASLOCO_PLUGIN_NAME ), trasloco_error_message( $e, 'import' ) );
							Trasloco_Notification::error( __( 'The import stopped', TRASLOCO_PLUGIN_NAME ), $e->getMessage() );
							// The storage parameter may be the invalid part of the request
							try {
								Trasloco_Directory::delete( trasloco_storage_path( $params ) );
							} catch ( Exception $ignored ) {
							}
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
						if ( isset( $params['trasloco_manual_import'] ) || isset( $params['trasloco_manual_restore'] ) ) {
							echo json_encode( $params );
							exit;
						}

						wp_remote_post( apply_filters( 'trasloco_http_import_url', admin_url( 'admin-ajax.php?action=trasloco_import' ) ), array(
							'timeout'   => apply_filters( 'trasloco_http_import_timeout', 5 ),
							'blocking'  => apply_filters( 'trasloco_http_import_blocking', false ),
							'sslverify' => apply_filters( 'trasloco_http_import_sslverify', true ),
							'headers'   => apply_filters( 'trasloco_http_import_headers', array() ),
							'body'      => apply_filters( 'trasloco_http_import_body', $params ),
						) );
						exit;
					}
				}

				next( $filters );
			}
		}
	}


	public static function http_import_headers( $headers = array() ) {
		if ( ( $user = get_option( TRASLOCO_AUTH_USER ) ) && ( $password = trasloco_get_http_password() ) ) {
			if ( ( $hash = base64_encode( sprintf( '%s:%s', $user, $password ) ) ) ) {
				$headers['Authorization'] = sprintf( 'Basic %s', $hash );
			}
		}

		return $headers;
	}

	public static function max_chunk_size() {
		// The chunk travels base64-encoded (a third larger) in a plain POST field, not as a file:
		// keep the whole request under post_max_size (0 means no limit)
		$post_max = (int) ( trasloco_parse_size( ini_get( 'post_max_size' ), 0 ) * 0.7 );

		return min( $post_max > 0 ? $post_max : TRASLOCO_MAX_CHUNK_SIZE, TRASLOCO_MAX_CHUNK_SIZE );
	}
}
