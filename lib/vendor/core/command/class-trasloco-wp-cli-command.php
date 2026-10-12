<?php
/**
 * Trasloco WP-CLI command
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
 */

if ( class_exists( 'WP_CLI_Command' ) ) {
	class Trasloco_WP_CLI_Command extends WP_CLI_Command {
		/**
		 * Creates a new backup.
		 *
		 * ## OPTIONS
		 *
		 * [--list]
		 * Get a list of backup files
		 *
		 * Example:
		 * $ wp trasloco backup --list
		 * +------------------------------------------------+--------------+-----------+
		 * | Backup name                                    | Date created | Size      |
		 * +------------------------------------------------+--------------+-----------+
		 * | migration-wp-20170908-152313-435.wpress        | 4 days ago   | 536.77 MB |
		 * | migration-wp-20170908-152103-603.wpress        | 4 days ago   | 536.77 MB |
		 * | migration-wp-20170908-152036-162.wpress        | 4 days ago   | 536.77 MB |
		 * | migration-wp-20170908-151428-266.wpress        | 4 days ago   | 536.77 MB |
		 * +------------------------------------------------+--------------+-----------+
		 *
		 * [--keep=<count>]
		 * After a successful backup, retain only the newest count archives on this site.
		 * This is opt-in; by default no existing backups are deleted.
		 *
		 * [--exclude-spam-comments]
		 * Do not export spam comments
		 *
		 * [--exclude-post-revisions]
		 * Do not export post revisions
		 *
		 * [--exclude-media]
		 * Do not export media library (files)
		 *
		 * [--exclude-themes]
		 * Do not export themes (files)
		 *
		 * [--exclude-inactive-themes]
		 * Do not export inactive themes (files)
		 *
		 * [--exclude-muplugins]
		 * Do not export must-use plugins (files)
		 *
		 * [--exclude-plugins]
		 * Do not export plugins (files)
		 *
		 * [--exclude-inactive-plugins]
		 * Do not export inactive plugins (files)
		 *
		 * [--exclude-cache]
		 * Do not export cache (files)
		 *
		 * [--exclude-database]
		 * Do not export database (sql)
		 *
		 * [--exclude-email-replace]
		 * Do not replace email domain (sql)
		 *
		 * [--replace "find" "replace"]
		 * Find and replace text in the database
		 *
		 * Example:
		 * $ wp trasloco backup --replace "wp" "WordPress"
		 * Backup in progress...
		 * Backup complete.
		 * Backup location: /repos/migration/wp/wp-content/trasloco-backups/migration-wp-20170913-095743-931.wpress
		 *
		 * @subcommand backup
		 */
		public function backup( array $args, array $assoc_args ) {
			if ( is_multisite() ) {
				WP_CLI::error( __( 'WP-CLI is not supported in Multisite mode. Please use the web interface to create a backup.', TRASLOCO_PLUGIN_NAME ) );
			}

			if ( ! is_dir( TRASLOCO_STORAGE_PATH ) ) {
				if ( ! mkdir( TRASLOCO_STORAGE_PATH ) ) {
					WP_CLI::error_multi_line( array(
						sprintf( __( 'Trasloco cannot create the folder <strong>%s</strong>. Create it and make it writable by the web server.', TRASLOCO_PLUGIN_NAME ), TRASLOCO_STORAGE_PATH ),
					) );
					exit;
				}
			}

			if ( ! is_dir( TRASLOCO_BACKUPS_PATH ) ) {
				if ( ! mkdir( TRASLOCO_BACKUPS_PATH ) ) {
					WP_CLI::error_multi_line( array(
						sprintf( __( 'Trasloco cannot create the folder <strong>%s</strong>. Create it and make it writable by the web server.', TRASLOCO_PLUGIN_NAME ), TRASLOCO_BACKUPS_PATH ),
					) );
					exit;
				}
			}

			$params = array();
			if ( isset( $assoc_args['list'] ) ) {
				$backups = new cli\Table;

				$backups->setHeaders( array(
					'name' => __( 'Backup name', TRASLOCO_PLUGIN_NAME ),
					'date' => __( 'Date created', TRASLOCO_PLUGIN_NAME ),
					'size' => __( 'Size', TRASLOCO_PLUGIN_NAME ),
				) );

				$model = new Trasloco_Backups;
				foreach ( $model->get_files() as $backup ) {
					$backups->addRow( array(
						'name' => $backup['filename'],
						'date' => sprintf( __( '%s ago', TRASLOCO_PLUGIN_NAME ), human_time_diff( $backup['mtime'] ) ),
						'size' => size_format( $backup['size'], 2 ),
					) );
				}

				$backups->display();
				exit;
			}

			if ( isset( $assoc_args['exclude-spam-comments'] ) ) {
				$params['options']['no_spam_comments'] = true;
			}

			if ( isset( $assoc_args['exclude-post-revisions'] ) ) {
				$params['options']['no_post_revisions'] = true;
			}

			if ( isset( $assoc_args['exclude-media'] ) ) {
				$params['options']['no_media'] = true;
			}

			if ( isset( $assoc_args['exclude-themes'] ) ) {
				$params['options']['no_themes'] = true;
			}

			if ( isset( $assoc_args['exclude-inactive-themes'] ) ) {
				$params['options']['no_inactive_themes'] = true;
			}

			if ( isset( $assoc_args['exclude-muplugins'] ) ) {
				$params['options']['no_muplugins'] = true;
			}

			if ( isset( $assoc_args['exclude-plugins'] ) ) {
				$params['options']['no_plugins'] = true;
			}

			if ( isset( $assoc_args['exclude-inactive-plugins'] ) ) {
				$params['options']['no_inactive_plugins'] = true;
			}

			if ( isset( $assoc_args['exclude-cache'] ) ) {
				$params['options']['no_cache'] = true;
			}

			if ( isset( $assoc_args['exclude-database'] ) ) {
				$params['options']['no_database'] = true;
			}

			if ( isset( $assoc_args['exclude-email-replace'] ) ) {
				$params['options']['no_email_replace'] = true;
			}

			if ( isset( $assoc_args['replace'] ) ) {
				for ( $i = 0; $i < count( $args ); $i++ ) {
					if ( isset( $args[ $i ] ) && isset( $args[ $i + 1 ] ) ) {
						$params['options']['replace']['old_value'][] = $args[ $i ];
						$params['options']['replace']['new_value'][] = $args[ $i + 1 ];
					}
				}
			}

			WP_CLI::log( 'Backup in progress...' );

			try {

				// Disable completed timeout
				add_filter( 'trasloco_completed_timeout', '__return_zero' );

				// Remove filters
				remove_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );

				// Run filters
				$params = apply_filters( 'trasloco_export', $params );

			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}

			// Clean storage folder
			Trasloco_Directory::delete( trasloco_storage_path( $params ) );

			WP_CLI::log( __( 'Backup complete.', TRASLOCO_PLUGIN_NAME ) );
			WP_CLI::log( sprintf( __( 'Backup location: %s', TRASLOCO_PLUGIN_NAME ), trasloco_backup_path( $params ) ) );
			// Opt-in retention for unattended schedules. Keep newest backups
			// only after the current archive was successfully created.
			if ( isset( $assoc_args['keep'] ) ) {
				$keep = filter_var( $assoc_args['keep'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( $keep === false ) {
					WP_CLI::error( '--keep must be a positive integer.' );
				}
				$archive_list = new Trasloco_Backups;
				foreach ( array_slice( $archive_list->get_files(), $keep ) as $old_backup ) {
					if ( ! $archive_list->delete_file( $old_backup['filename'] ) ) {
						WP_CLI::warning( 'Unable to remove old backup: ' . $old_backup['filename'] );
					}
				}
			}
		}

		/**
		 * Verify a .wpress backup against its SHA-256 sidecar.
		 *
		 * ## OPTIONS
		 * <archive>
		 * : Filename of a local .wpress backup.
		 *
		 * @subcommand verify
		 */
		public function verify( array $args, array $assoc_args ) {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( 'Provide a .wpress backup filename.' );
			}
			try {
				$result = Trasloco_Manifest::inspect( $args[0], true );
			} catch ( Exception $e ) {
				WP_CLI::error( 'Invalid archive name.' );
			}
			if ( $result['status'] !== 'verified' ) {
				WP_CLI::error( $result['status'] . ': ' . $result['reason'] );
			}
			WP_CLI::success( 'SHA-256 verified: ' . $result['sha256'] );
		}

		/**
		 * Encrypt a verified backup to an independently authenticated archive.
		 * The original plaintext backup is never deleted automatically.
		 * @subcommand encrypt
		 */
		public function encrypt( array $args, array $assoc_args ) {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( 'Usage: wp trasloco encrypt <name.wpress>' );
			}
			$password = getenv( 'TRASLOCO_BACKUP_PASSPHRASE' );
			if ( ! is_string( $password ) || strlen( $password ) < 12 ) {
				WP_CLI::error( 'Set TRASLOCO_BACKUP_PASSPHRASE to at least 12 characters.' );
			}
			try {
				$verified = Trasloco_Manifest::inspect( $args[0] );
				if ( $verified['status'] !== 'verified' ) {
					WP_CLI::error( 'Backup integrity must be verified before encryption.' );
				}
				$source = Trasloco_Manifest::archive_path( $args[0] );
				WP_CLI::success( 'Encrypted file: ' . Trasloco_Crypt::encrypt_file( $source, $source . '.enc', $password ) );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		/**
		 * Decrypt a .wpress.enc backup. Never overwrite an existing .wpress.
		 * @subcommand decrypt
		 */
		public function decrypt( array $args, array $assoc_args ) {
			if ( empty( $args[0] ) || ! preg_match( '/^[A-Za-z0-9_.-]+\\.wpress\\.enc$/D', $args[0] ) ) {
				WP_CLI::error( 'Usage: wp trasloco decrypt <name.wpress.enc>' );
			}
			$password = getenv( 'TRASLOCO_BACKUP_PASSPHRASE' );
			if ( ! is_string( $password ) || strlen( $password ) < 12 ) {
				WP_CLI::error( 'Set TRASLOCO_BACKUP_PASSPHRASE to at least 12 characters.' );
			}
			try {
				$name = substr( $args[0], 0, -4 );
				$dest = Trasloco_Manifest::archive_path( $name );
				$source = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $args[0];
				Trasloco_Crypt::decrypt_file( $source, $dest, $password );
				Trasloco_Manifest::create( $name );
				WP_CLI::success( 'Decrypted and verified file: ' . $dest );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		/**
		 * Transfer a backup to/from configured private offsite storage.
		 * Upload plain .wpress only with explicit --allow-unencrypted.
		 * @subcommand offsite
		 */
		public function offsite( array $args, array $assoc_args ) {
			if ( count( $args ) !== 2 || ! in_array( $args[0], array( 'upload', 'download' ), true ) ) {
				WP_CLI::error( 'Usage: wp trasloco offsite upload|download <filename.wpress[.enc]>' );
			}
			$action = $args[0];
			$name = $args[1];
			try {
				Trasloco_Storage::validate_object( $name );
				if ( substr( $name, -14 ) === '.manifest.json' ) {
					throw new RuntimeException( 'Transfer the main backup, not the manifest separately.' );
				}
				$encrypted = substr( $name, -4 ) === '.enc';
				$destination = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $name;
				$provider = Trasloco_Storage::provider();
				if ( $action === 'upload' ) {
					if ( ! $encrypted ) {
						if ( empty( $assoc_args['allow-unencrypted'] ) ) {
							throw new RuntimeException( 'Unencrypted offsite uploads require --allow-unencrypted.' );
						}
						$verification = Trasloco_Manifest::inspect( $name );
						if ( $verification['status'] !== 'verified' ) {
							throw new RuntimeException( 'Backup failed integrity verification.' );
						}
					}
					$provider->upload( $destination, $name );
					if ( ! $encrypted ) {
						$provider->upload( $destination . Trasloco_Manifest::SUFFIX, $name . Trasloco_Manifest::SUFFIX );
					}
					WP_CLI::success( 'Uploaded backup to offsite storage.' );
				} else {
					if ( file_exists( $destination ) || is_link( $destination ) ) {
						throw new RuntimeException( 'Local archive already exists. Refusing overwrite.' );
					}
					$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-offsite-' . bin2hex( random_bytes( 12 ) );
					$manifest_temp = $temp . '.manifest.json';
					try {
						$provider->download( $name, $temp );
						if ( ! $encrypted ) {
							$provider->download( $name . Trasloco_Manifest::SUFFIX, $manifest_temp );
						}
						if ( ! @rename( $temp, $destination ) ) {
							throw new RuntimeException( 'Could not save downloaded archive.' );
						}
						if ( ! $encrypted ) {
							if ( ! @rename( $manifest_temp, $destination . Trasloco_Manifest::SUFFIX ) ) {
								@unlink( $destination );
								throw new RuntimeException( 'Could not save downloaded integrity manifest.' );
							}
							$checked = Trasloco_Manifest::inspect( $name );
							if ( $checked['status'] !== 'verified' ) {
								@unlink( $destination );
								@unlink( $destination . Trasloco_Manifest::SUFFIX );
								throw new RuntimeException( 'Downloaded archive failed verification.' );
							}
						}
						WP_CLI::success( 'Downloaded backup from offsite storage.' );
					} finally {
						@unlink( $temp );
						@unlink( $manifest_temp );
					}
				}
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		/**
		 * Restores a backup.
		 *
		 * Example:
		 * $ wp trasloco restore migration-wp-20170913-095743-931.wpress
		 * Restore in progress...
		 * Restore complete.
		 *
		 * @subcommand restore
		 */
		public function restore( array $args, array $assoc_args ) {
			if ( is_multisite() ) {
				WP_CLI::error( __( 'WP-CLI is not supported in Multisite mode. Please use the web interface to restore a backup.', TRASLOCO_PLUGIN_NAME ) );
			}

			if ( ! is_dir( TRASLOCO_STORAGE_PATH ) ) {
				if ( ! mkdir( TRASLOCO_STORAGE_PATH ) ) {
					WP_CLI::error_multi_line( array(
						sprintf( __( 'Trasloco cannot create the folder <strong>%s</strong>. Create it and make it writable by the web server.', TRASLOCO_PLUGIN_NAME ), TRASLOCO_STORAGE_PATH ),
					) );
					exit;
				}
			}

			$params = array();
			if ( isset( $args[0] ) && is_file( trasloco_backup_path( array( 'archive' => $args[0] ) ) ) ) {
				$params = array(
					'storage'              => trasloco_storage_folder(),
					'archive'              => $args[0],
					'trasloco_manual_restore' => true,
				);
			} else {
				WP_CLI::error_multi_line( array(
					__( 'A valid backup name must be provided in order to proceed with the restore process.', TRASLOCO_PLUGIN_NAME ),
					__( 'Example: wp trasloco restore migration-wp-20170913-095743-931.wpress', TRASLOCO_PLUGIN_NAME ),
				) );
				exit;
			}

			WP_CLI::log( 'Restore in progress...' );

			try {

				// Disable completed timeout
				add_filter( 'trasloco_completed_timeout', '__return_zero' );

				// Remove filters
				remove_filter( 'trasloco_import', 'Trasloco_Import_Upload::execute', 5 );
				remove_filter( 'trasloco_import', 'Trasloco_Import_Confirm::execute', 100 );
				remove_filter( 'trasloco_import', 'Trasloco_Import_Clean::execute', 400 );

				// Run filters
				$params = apply_filters( 'trasloco_import', $params );

			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}

			// Clean storage folder
			Trasloco_Directory::delete( trasloco_storage_path( $params ) );

			WP_CLI::log( 'Restore complete.' );
		}
	}
}
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'trasloco', 'Trasloco_WP_CLI_Command', array( 'short_desc' => 'Back up and restore the site with Trasloco' ) );
}
