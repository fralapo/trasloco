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

			$keep = null;
			if ( isset( $assoc_args['keep'] ) ) {
				$keep = filter_var( $assoc_args['keep'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( $keep === false ) {
					WP_CLI::error( '--keep must be a positive integer.' );
				}
			}

			WP_CLI::log( 'Backup in progress...' );
			$lock_owner = hash( 'sha256', 'trasloco-cli-backup:' . wp_generate_uuid4() );
			$backup_lock = Trasloco_Export_Lock::acquire( $lock_owner, HOUR_IN_SECONDS );
			if ( $backup_lock === false ) {
				WP_CLI::error( __( 'Another site export is already running.', TRASLOCO_PLUGIN_NAME ) );
			}
			$GLOBALS['trasloco_active_export_lock'] = array(
				'handle' => $backup_lock, 'owner' => $lock_owner,
				'lease_seconds' => HOUR_IN_SECONDS, 'last_renew' => time(),
			);
			register_shutdown_function( function () use ( &$backup_lock, $lock_owner ) {
				if ( is_resource( $backup_lock ) ) {
					unset( $GLOBALS['trasloco_active_export_lock'] );
					Trasloco_Export_Lock::release( $backup_lock, $lock_owner );
				}
			} );

			try {

				// Disable completed timeout
				add_filter( 'trasloco_completed_timeout', '__return_zero' );

				// Remove filters
				remove_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );

				// Run filters
				$params = apply_filters( 'trasloco_export', $params );

			} catch ( Exception $e ) {
				unset( $GLOBALS['trasloco_active_export_lock'] );
				Trasloco_Export_Lock::release( $backup_lock, $lock_owner );
				WP_CLI::error( $e->getMessage() );
			}

			// Clean storage folder
			Trasloco_Directory::delete( trasloco_storage_path( $params ) );

			WP_CLI::log( __( 'Backup complete.', TRASLOCO_PLUGIN_NAME ) );
			WP_CLI::log( sprintf( __( 'Backup location: %s', TRASLOCO_PLUGIN_NAME ), trasloco_backup_path( $params ) ) );
			// Opt-in retention for unattended schedules. Keep newest backups
			// only after the current archive was successfully created.
			if ( $keep !== null ) {
				$archive_list = new Trasloco_Backups;
				foreach ( array_slice( $archive_list->get_retention_files(), $keep ) as $old_backup ) {
					if ( ! $archive_list->delete_file( $old_backup['filename'] ) ) {
						WP_CLI::warning( 'Unable to remove old backup: ' . $old_backup['filename'] );
					}
				}
			}
			unset( $GLOBALS['trasloco_active_export_lock'] );
			Trasloco_Export_Lock::release( $backup_lock, $lock_owner );
		}

		/**
		 * Transfer a verified archive through a configured private storage adapter.
		 *
		 * ## OPTIONS
		 * <upload|download|queue-upload|queue-download|retry-upload|retry-download|status>
		 * [<archive>]
		 * [<job-id>]
		 * [--allow-unencrypted]
		 * Permit uploading a plaintext .wpress archive. Encrypted .wpress.enc files
		 * do not need this option.
		 *
		 * ## EXAMPLES
		 *     wp trasloco offsite upload backup.wpress.enc
		 *     wp trasloco offsite upload backup.wpress --allow-unencrypted
		 *     wp trasloco offsite download backup.wpress.enc
		 *     wp trasloco offsite queue-upload backup.wpress.enc
		 *     wp trasloco offsite queue-download backup.wpress.enc
		 *     wp trasloco offsite retry-upload 123
		 *     wp trasloco offsite retry-download 123
		 *     wp trasloco offsite status
		 *
		 * @subcommand offsite
		 */
		public function offsite( array $args, array $assoc_args ) {
			$operations = array( 'upload', 'download', 'queue-upload', 'queue-download', 'retry-upload', 'retry-download', 'status' );
			if ( empty( $args[0] ) || ! in_array( $args[0], $operations, true ) ||
				( $args[0] === 'status' ? count( $args ) !== 1 : count( $args ) !== 2 ) ) {
				WP_CLI::error( 'Usage: wp trasloco offsite <upload|download|queue-upload|queue-download> <archive> [--allow-unencrypted] | <retry-upload|retry-download> <job-id> | status.' );
				return;
			}
			if ( $args[0] === 'queue-upload' ) {
				$id = Trasloco_Job_Worker::enqueue_offsite_upload( $args[1], isset( $assoc_args['allow-unencrypted'] ) );
				if ( is_wp_error( $id ) ) {
					WP_CLI::error( $id->get_error_message() );
				}
				WP_CLI::success( sprintf( 'Offsite upload queued as job %d. Check progress in the Trasloco jobs panel.', $id ) );
				return;
			}
			if ( $args[0] === 'queue-download' ) {
				$id = Trasloco_Job_Worker::enqueue_offsite_download( $args[1] );
				if ( is_wp_error( $id ) ) {
					WP_CLI::error( $id->get_error_message() );
				}
				WP_CLI::success( sprintf( 'Offsite download queued as job %d. Check progress in the Trasloco jobs panel.', $id ) );
				return;
			}
			if ( in_array( $args[0], array( 'retry-upload', 'retry-download' ), true ) ) {
				$id = ctype_digit( (string) $args[1] ) ? (int) $args[1] : 0;
				if ( $id <= 0 || ! Trasloco_Job_Store::retry_transfer( $id ) || ! Trasloco_Job_Worker::schedule( $id ) ) {
					WP_CLI::error( 'Unable to retry this offsite transfer job.' );
				}
				WP_CLI::success( sprintf( 'Offsite transfer job %d queued for retry.', $id ) );
				return;
			}
			if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
				WP_CLI::error( 'Unable to prepare the local backup directory.' );
				return;
			}
			try {
				require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
				$adapter = Trasloco_Storage_Factory::configured();
				$transfer = new Trasloco_Offsite_Transfer( $adapter, TRASLOCO_BACKUPS_PATH );
				if ( $args[0] === 'status' ) {
					$report = $transfer->inventory_status();
					WP_CLI::log( sprintf(
						'Offsite storage: %s; %d complete, %d incomplete.',
						$report['status'],
						$report['complete'],
						$report['incomplete']
					) );
					foreach ( $report['archives'] as $item ) {
						WP_CLI::log( $item['status'] . ': ' . $item['archive'] );
					}
					if ( $report['status'] !== 'healthy' ) {
						WP_CLI::error( 'Remote inventory is incomplete; review the listed archive/manifest pairs.' );
					}
					WP_CLI::success( 'Remote storage is reachable and every listed archive has a manifest.' );
					return;
				} elseif ( $args[0] === 'upload' ) {
					$result = $transfer->upload( $args[1], isset( $assoc_args['allow-unencrypted'] ) );
				} else {
					$result = $transfer->download( $args[1] );
				}
				$verb = $result['status'] === 'already_present' ? 'already verified at' :
					( $args[0] === 'upload' ? 'uploaded to' : 'downloaded from' );
				WP_CLI::success( sprintf( '%s %s offsite (%d bytes).', $result['archive'], $verb, $result['bytes'] ) );
			} catch ( Exception $error ) {
				WP_CLI::error( $error->getMessage() );
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
				require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
				$source = trasloco_backup_path( array( 'archive' => $args[0] ) );
				$encrypted = $source . '.enc';
				$manifest = Trasloco_Integrity::manifest_path( $encrypted );
				if ( file_exists( $encrypted ) || is_link( $encrypted ) || file_exists( $manifest ) || is_link( $manifest ) ) {
					WP_CLI::error( 'Encrypted backup or its manifest already exists; refusing to replace it.' );
					return;
				}
				require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-encryption.php';
				Trasloco_Encryption::encrypt( $source, $encrypted, $passphrase );
				try {
					Trasloco_Integrity::create( $encrypted );
				} catch ( Exception $error ) {
					@unlink( $encrypted );
					@unlink( $manifest );
					throw $error;
				}
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
		 * Pull a verified backup from another Trasloco WordPress site.
		 *
		 * ## OPTIONS
		 * <source-url>
		 * HTTPS URL of the source WordPress site.
		 * <archive>
		 * Verified .wpress archive basename on the source site.
		 *
		 * Set TRASLOCO_PEER_USER and TRASLOCO_PEER_APP_PASSWORD in the process
		 * environment to a dedicated WordPress Application Password.
		 *
		 * @subcommand pull-site
		 */
		public function pull_site( array $args, array $assoc_args ) {
			if ( count( $args ) < 2 ) {
				WP_CLI::error( 'Usage: wp trasloco pull-site <https://source.example> <archive.wpress>' );
			}
			$user = getenv( 'TRASLOCO_PEER_USER' );
			$password = getenv( 'TRASLOCO_PEER_APP_PASSWORD' );
			try {
				$path = Trasloco_Peer::pull( $args[0], $args[1], $user, $password );
				WP_CLI::success( 'Verified remote backup saved as ' . basename( $path ) . '.' );
			} catch ( Throwable $error ) {
				WP_CLI::error( $error->getMessage() );
			}
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
		 * Restore the exact recovery archive recorded for a failed import.
		 *
		 * ## OPTIONS
		 * <receipt-id>
		 * The 32-character identifier from `php tools/recovery-status.php --json`.
		 * [--yes]
		 * Apply the displayed plan. Without this flag the command is read-only.
		 *
		 * @subcommand restore-recovery
		 */
		public function restore_recovery( array $args, array $assoc_args ) {
			if ( count( $args ) !== 1 || ! is_string( $args[0] ) ||
				! preg_match( '/^[a-f0-9]{32}$/D', $args[0] ) ) {
				WP_CLI::error( 'Usage: wp trasloco restore-recovery <receipt-id> [--yes]' );
			}

			require_once TRASLOCO_LIB_PATH . '/model/import/class-trasloco-recovery-journal.php';
			require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
			require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-archive-inspector.php';
			$receipt = Trasloco_Recovery_Journal::get( TRASLOCO_BACKUPS_PATH, $args[0] );
			if ( ! is_array( $receipt ) || $receipt['status'] !== 'pending' ) {
				WP_CLI::error( 'The pending recovery receipt was not found. No changes were made.' );
			}

			try {
				$archive_path = trasloco_backup_path( array( 'archive' => $receipt['recovery_archive'] ) );
				if ( Trasloco_Integrity::verify( $archive_path )['status'] !== 'verified' ) {
					throw new RuntimeException( 'Recovery archive checksum verification failed.' );
				}
				$inventory = Trasloco_Archive_Inspector::inspect( $archive_path, 0 );
				if ( empty( $inventory['restore_inputs']['package.json'] ) ||
					empty( $inventory['restore_inputs']['database.sql'] ) ) {
					throw new RuntimeException( 'Required restore metadata or database SQL is missing.' );
				}
			} catch ( Throwable $error ) {
				WP_CLI::error( 'The recovery archive could not be safely inspected. No changes were made.' );
			}

			WP_CLI::line( 'Failed import: ' . $receipt['source_archive'] );
			WP_CLI::line( 'Recovery archive: ' . $receipt['recovery_archive'] );
			WP_CLI::line( 'Receipt created (UTC): ' . $receipt['created_at'] );
			WP_CLI::line( 'Archive integrity: SHA-256 verified' );
			WP_CLI::line( 'Restore payload: ' . (int) $inventory['files_in_archive'] . ' archive entries and database.sql' );
			WP_CLI::warning( 'This restores the recorded database and archived files. It does not remove files absent from the snapshot. Stop site writes and review the separate file-recovery quarantine before reopening traffic.' );
			if ( empty( $assoc_args['yes'] ) ) {
				WP_CLI::success( 'Read-only recovery plan complete. No changes were made. Repeat with --yes to apply it.' );
				return;
			}

			$this->restore( array( $receipt['recovery_archive'] ), array() );
			WP_CLI::warning( 'Database and archived files were restored. Keep the site offline while reviewing archive-absent files with tools/recovery-files.php.' );
		}

		/**
		 * Restore selected database tables from a verified local backup.
		 *
		 * ## OPTIONS
		 * <archive>
		 * --tables=<suffixes>
		 * Comma-separated single-site table suffixes, for example posts,postmeta.
		 * [--yes]
		 * Required to replace the selected tables after the read-only plan is shown.
		 *
		 * @subcommand restore-tables
		 */
		public function restore_tables( array $args, array $assoc_args ) {
			global $wpdb;
			require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-selective-restore-lock.php';
			if ( Trasloco_Selective_Restore_Lock::active() ) {
				WP_CLI::error( 'A selective restore is already running. Wait for it to finish before starting another restore.' );
			}
			if ( is_multisite() ) {
				WP_CLI::error( 'Selective table restore is available only on single-site WordPress.' );
			}
			if ( count( $args ) !== 1 || empty( $assoc_args['tables'] ) || ! is_string( $assoc_args['tables'] ) ) {
				WP_CLI::error( 'Usage: wp trasloco restore-tables <archive.wpress> --tables=posts,postmeta [--yes]' );
			}
			if ( ! preg_match( '/^[A-Za-z0-9_.-]+\.wpress$/D', $args[0] ) ) {
				WP_CLI::error( 'Specify an archive filename from the local backup directory.' );
			}
			$requested = explode( ',', $assoc_args['tables'] );
			if ( count( $requested ) > 50 ) {
				WP_CLI::error( 'Select no more than 50 tables per operation.' );
			}
			$requested = array_values( array_unique( $requested ) );
			foreach ( $requested as $table ) {
				if ( ! preg_match( '/^[A-Za-z0-9_$]{1,180}$/D', $table ) ) {
					WP_CLI::error( 'Table suffixes may contain only letters, digits, underscore and dollar sign.' );
				}
			}

			$archive_path = trasloco_backup_path( array( 'archive' => $args[0] ) );
			require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
			require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-offline-sql-preview.php';
			$verification = Trasloco_Integrity::verify( $archive_path );
			if ( $verification['status'] !== 'verified' ) {
				WP_CLI::error( 'Selective restore requires a valid SHA-256 manifest for the backup.' );
			}
			try {
				$inventory = Trasloco_Offline_SQL_Preview::preview( $archive_path );
			} catch ( Throwable $error ) {
				WP_CLI::error( 'The backup SQL inventory could not be verified safely.' );
			}
			if ( $inventory['database_sql'] !== 'present' ) {
				WP_CLI::error( 'The selected backup has no database SQL to restore.' );
			}

			$tables = array();
			$destination_tables = $wpdb->get_col( 'SHOW TABLES' );
			if ( ! is_array( $destination_tables ) ) {
				WP_CLI::error( 'Could not read destination table names.' );
			}
			foreach ( $requested as $suffix ) {
				$source_table = 'SERVMASK_PREFIX_' . $suffix;
				if ( ! isset( $inventory['tables'][ $source_table ] ) ) {
					WP_CLI::error( 'The selected table is not present in the verified backup: ' . $suffix );
				}
				$actions = $inventory['tables'][ $source_table ];
				if ( $actions['drop'] < 1 || $actions['create'] < 1 ) {
					WP_CLI::error( 'The backup does not contain a complete DROP/CREATE operation for: ' . $suffix );
				}
				$destination_table = $wpdb->prefix . $suffix;
				if ( strlen( $destination_table ) > 64 ) {
					WP_CLI::error( 'The destination table name exceeds the MySQL identifier limit.' );
				}
				$exists = in_array( $destination_table, $destination_tables, true );
				$effect = $exists ? 'replace existing table' : 'create table';
				WP_CLI::line( sprintf( '%s -> %s (%s)', $source_table, $destination_table, $effect ) );
				$tables[] = $source_table;
			}
			WP_CLI::warning( 'This operation replaces selected tables only. Pause site writes first; restoring related tables separately can leave inconsistent data. A verified full-site recovery point is created before SQL changes.' );
			if ( empty( $assoc_args['yes'] ) ) {
				WP_CLI::success( 'Read-only plan complete. No tables were changed. Repeat with --yes to apply it.' );
				return;
			}
			if ( ! is_dir( TRASLOCO_STORAGE_PATH ) && ! mkdir( TRASLOCO_STORAGE_PATH, 0700, true ) ) {
				WP_CLI::error( 'Cannot create the private import storage directory.' );
			}

			$params = array(
				'storage' => bin2hex( random_bytes( 16 ) ),
				'archive' => $args[0],
				'trasloco_manual_restore' => true,
				'trasloco_selective_tables' => $tables,
				'trasloco_selected_tables' => $tables,
			);
			$lock_owner = bin2hex( random_bytes( 16 ) );
			if ( ! Trasloco_Selective_Restore_Lock::acquire( $lock_owner ) ) {
				WP_CLI::error( 'Could not acquire the selective restore lease. No tables were changed.' );
			}
			WP_CLI::log( 'Creating a verified safety backup, then restoring the selected tables...' );
			$error = null;
			add_filter( 'trasloco_completed_timeout', '__return_zero' );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Upload::execute', 5 );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Confirm::execute', 100 );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Content::execute', 250 );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Mu_Plugins::execute', 270 );
			remove_filter( 'trasloco_import', 'Trasloco_Import_Clean::execute', 400 );
			try {
				$params = apply_filters( 'trasloco_import', $params );
			} catch ( Throwable $caught ) {
				$error = $caught;
			} finally {
				Trasloco_Selective_Restore_Lock::release( $lock_owner );
				$storage_path = trasloco_storage_path( $params );
				if ( is_dir( $storage_path ) ) {
					Trasloco_Directory::delete( $storage_path );
				}
			}
			if ( $error ) {
				WP_CLI::error( $error->getMessage() );
			}
			WP_CLI::success( 'Selected database tables were restored. Recovery point: ' . basename( $params['recovery_archive'] ) );
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
			require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-selective-restore-lock.php';
			if ( Trasloco_Selective_Restore_Lock::active() ) {
				WP_CLI::error( 'A selective restore is already running. Wait for it to finish before starting another restore.' );
			}
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
