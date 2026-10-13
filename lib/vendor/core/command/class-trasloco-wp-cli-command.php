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
				foreach ( array_slice( $archive_list->get_retention_files(), $keep ) as $old_backup ) {
					if ( ! $archive_list->delete_file( $old_backup['filename'] ) ) {
						WP_CLI::warning( 'Unable to remove old backup: ' . $old_backup['filename'] );
					}
				}
			}
		}


		/**
		 * Encrypt an archive into a .wpress.enc file using a libsodium stream.
		 * Set TRASLOCO_ARCHIVE_PASSPHRASE securely in the environment.
		 *
		 * @subcommand encrypt
		 */
		public function encrypt( array $args, array $assoc_args ) {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( 'Specify a .wpress archive filename.' );
			}
			$passphrase = getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
			if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
				WP_CLI::error( 'Set TRASLOCO_ARCHIVE_PASSPHRASE (at least 12 characters).' );
			}
			try {
				$source = trasloco_backup_path( array( 'archive' => $args[0] ) );
				require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
				Trasloco_Encryption::encrypt( $source, $source . '.enc', $passphrase );
				WP_CLI::success( 'Encrypted backup: ' . basename( $source ) . '.enc' );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		/**
		 * Decrypt a .wpress.enc archive into a new, verified .wpress file.
		 * Does not overwrite either the encrypted source or existing output.
		 *
		 * @subcommand decrypt
		 */
		public function decrypt( array $args, array $assoc_args ) {
			if ( empty( $args[0] ) || ! is_string( $args[0] ) ||
				! preg_match( '/^[a-zA-Z0-9_.-]+\.wpress\.enc$/D', $args[0] ) ) {
				WP_CLI::error( 'Specify a valid .wpress.enc filename.' );
			}
			$passphrase = getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
			if ( ! is_string( $passphrase ) || strlen( $passphrase ) < 12 ) {
				WP_CLI::error( 'Set TRASLOCO_ARCHIVE_PASSPHRASE (at least 12 characters).' );
			}
			try {
				$name = substr( $args[0], 0, -11 );
				$source = trasloco_backup_path( array( 'archive' => $name . '.wpress' ) ) . '.enc';
				$target = trasloco_backup_path( array( 'archive' => $name . '-decrypted.wpress' ) );
				require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
				Trasloco_Encryption::decrypt( $source, $target, $passphrase );
				$check = new Trasloco_Extractor( $target );
				$valid = $check->is_valid();
				$check->close();
				if ( ! $valid ) {
					@unlink( $target );
					WP_CLI::error( 'Decrypted data is not a valid .wpress archive.' );
				}
				require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
				Trasloco_Integrity::create( $target );
				WP_CLI::success( 'Decrypted backup: ' . basename( $target ) );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}


		/**
		 * Extract a chosen uploads/themes/plugins file or directory into a
		 * private temporary folder. NEVER overwrites live WordPress content.
		 *
		 * ## OPTIONS
		 * <archive>
		 * <relative-file-or-directory>
		 *
		 * @subcommand extract-files
		 */
		public function extract_files( array $args, array $assoc_args ) {
			if ( count( $args ) < 2 || ! is_string( $args[1] ) ) {
				WP_CLI::error( 'Specify an archive and a relative path under uploads/, themes/, plugins/ or mu-plugins/.' );
			}
			$relative = str_replace( '\\', '/', $args[1] );
			if ( strlen( $relative ) > 512 || strpos( $relative, "\0" ) !== false ||
				preg_match( '#^(/|[A-Za-z]:)#', $relative ) ||
				! preg_match( '#^(uploads|themes|plugins|mu-plugins)/[^/]+#', $relative ) ||
				in_array( '..', explode( '/', $relative ), true ) ) {
				WP_CLI::error( 'Unsafe or unsupported restore path.' );
			}
			try {
				$source = trasloco_backup_path( array( 'archive' => $args[0] ) );
				$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trasloco-file-restore-' . wp_generate_password( 20, false, false );
				if ( ! mkdir( $directory, 0700 ) ) {
					WP_CLI::error( 'Unable to create private extraction folder.' );
				}
				$archive = new Trasloco_Extractor( $source );
				if ( ! $archive->is_valid() ) {
					$archive->close();
					WP_CLI::error( 'Invalid archive structure.' );
				}
				add_filter( 'trasloco_completed_timeout', '__return_zero' );
				try {
					$completed = $archive->extract_by_files_array( $directory, array( $relative ) );
				} finally {
					remove_filter( 'trasloco_completed_timeout', '__return_zero' );
					$archive->close();
				}
				if ( ! $completed ) {
					WP_CLI::error( 'Selective extraction did not complete.' );
				}
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
				);
				$count = 0;
				foreach ( $iterator as $item ) {
					if ( $item->isFile() ) { $count++; }
				}
				if ( $count < 1 ) {
					Trasloco_Directory::delete( $directory );
					WP_CLI::error( 'No files matched the requested path.' );
				}
				WP_CLI::success( 'Files extracted to: ' . $directory );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		/**
         * Show non-destructive hosting safety diagnostics.
         * This command never prints credentials or private filesystem paths.
         *
         * @subcommand doctor
         */
        public function doctor( array $args, array $assoc_args ) {
            $report = Trasloco_Preflight::report();
            if ( isset( $assoc_args['format'] ) && $assoc_args['format'] === 'json' ) {
                WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                return;
            }
            foreach ( array( 'backup_directory', 'backup_web_access', 'php_64_bit',
                'mysqli_available', 'sodium_available', 'wp_cron' ) as $field ) {
                WP_CLI::line( $field . ': ' . ( is_bool( $report[$field] )
                    ? ( $report[$field] ? 'yes' : 'no' ) : $report[$field] ) );
            }
            WP_CLI::line( 'free_space_bytes: ' .
                ( $report['free_space_bytes'] === null ? 'unknown' : $report['free_space_bytes'] ) );
            foreach ( $report['issues'] as $issue ) {
                WP_CLI::warning( $issue );
            }
            WP_CLI::log( $report['warning'] );
        }

		/**
		 * Verify a backup against its SHA-256 manifest.
		 *
		 * ## OPTIONS
		 * <archive>
		 * Existing archive basename inside the configured backup folder.
		 *
		 * @subcommand verify
		 */
		public function verify( array $args, array $assoc_args ) {
			if ( empty( $args[0] ) ) {
				WP_CLI::error( 'Specify an archive filename.' );
			}
			$archive = trasloco_backup_path( array( 'archive' => $args[0] ) );
			require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
			$result = Trasloco_Integrity::verify( $archive );
			if ( $result['status'] !== 'verified' ) {
				WP_CLI::error( $result['status'] . ': ' . $result['message'] );
			}
			WP_CLI::success( $result['message'] );
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
