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

class Trasloco_Backups_Controller {

	public static function index() {
		// Forget the status of the previous run, or the progress window shows it and stops
		delete_option( TRASLOCO_STATUS );

		$model = new Trasloco_Backups;

		Trasloco_Template::render(
			'backups/index',
			array(
				'backups'  => $model->get_files(),
				'username' => get_option( TRASLOCO_AUTH_USER ),
				'password' => get_option( TRASLOCO_AUTH_PASSWORD ),
			)
		);
	}

	public static function delete( $params = array() ) {
		$errors = array();

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Set secret key
		$secret_key = null;
		if ( isset( $params['secret_key'] ) ) {
			$secret_key = trim( $params['secret_key'] );
		}

		// Set archive
		$archive = null;
		if ( isset( $params['archive'] ) ) {
			$archive = trim( $params['archive'] );
		}

		try {
			// Ensure that unauthorized people cannot access delete action
			trasloco_verify_secret_key( $secret_key );
		} catch ( Trasloco_Not_Valid_Secret_Key_Exception $e ) {
			exit;
		}

		$model = new Trasloco_Backups;

		try {
			// Delete file
			$model->delete_file( $archive );
		} catch ( Exception $e ) {
			$errors[] = $e->getMessage();
		}

		echo json_encode( array( 'errors' => $errors ) );
		exit;
	}
}
