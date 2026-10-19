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
		 * [--incremental]
		 * Also capture the verified archive in the local content-addressed snapshot store.
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
			$incremental = isset( $assoc_args['incremental'] );

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
			if ( $incremental ) {
				$archive = trasloco_backup_path( $params );
				require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
				require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
				$integrity = Trasloco_Integrity::verify( $archive );
				if ( $integrity['status'] !== 'verified' ) {
					WP_CLI::error( 'Backup failed integrity verification before incremental capture.' );
				}
				$store_root = rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental';
				$store = new Trasloco_Incremental_Store( $store_root );
				$result = $store->capture( $archive, $integrity['sha256'] );
				WP_CLI::success( sprintf( 'Incremental snapshot captured (%d chunks; %d unique chunks).',
					$result['objects'], $result['unique_objects'] ) );
			}
			// Opt-in retention for unattended schedules. Keep newest backups
			// only after the current archive was successfully created.
			if ( $keep !== null ) {
				$archive_list = new Trasloco_Backups;
				if ( $incremental ) {
					require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
					$store = new Trasloco_Incremental_Store(
						rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental'
					);
					$archives = $archive_list->get_retention_files();
					$snapshots = $store->list_snapshots();
					$ordinary_keep = array_fill_keys( Trasloco_Backups::incremental_retention_names( $archives, $snapshots, $keep ), true );
					$snapshot_keep = array();
					foreach ( $snapshots as $snapshot ) {
						if ( Trasloco_Backups::is_recovery_point( $snapshot['archive'] ) || isset( $ordinary_keep[ $snapshot['archive'] ] ) ) {
							$snapshot_keep[] = $snapshot['archive'];
						}
					}
					if ( empty( $snapshot_keep ) ) {
						WP_CLI::error( 'Incremental retention would remove the newly captured snapshot.' );
					}
					$store->prune( $snapshot_keep );
					foreach ( $archives as $old_backup ) {
						if ( ! isset( $ordinary_keep[ basename( $old_backup['filename'] ) ] ) && ! $archive_list->delete_file( $old_backup['filename'] ) ) {
							WP_CLI::warning( 'Unable to remove old backup: ' . $old_backup['filename'] );
						}
					}
				} else {
					foreach ( array_slice( $archive_list->get_retention_files(), $keep ) as $old_backup ) {
						if ( ! $archive_list->delete_file( $old_backup['filename'] ) ) { WP_CLI::warning( 'Unable to remove old backup: ' . $old_backup['filename'] ); }
					}
				}
			}
			unset( $GLOBALS['trasloco_active_export_lock'] );
			Trasloco_Export_Lock::release( $backup_lock, $lock_owner );
		}

		/**
		 * Manage local content-addressed snapshots of verified .wpress archives.
		 *
		 * ## OPTIONS
		 * <capture|verify|materialize|prune|list|stats|compact>
		 * [<archive>]
		 *
		 * [--keep=<archives>]
		 * Comma-separated .wpress names to retain when pruning.
		 *
		 * [--output=<filename>]
		 * New .wpress filename for materialize (created in the backup directory).
		 *
		 * ## EXAMPLES
		 *     wp trasloco incremental capture backup.wpress
		 *     wp trasloco incremental verify backup.wpress
		 *     wp trasloco incremental materialize backup.wpress --output=restored.wpress
		 *     wp trasloco incremental prune --keep=backup.wpress,newer.wpress
		 *     wp trasloco incremental list
		 *     wp trasloco incremental stats
		 *     wp trasloco incremental compact backup.wpress
		 *     wp trasloco incremental upload backup.wpress --allow-unencrypted
		 *     wp trasloco incremental download backup.wpress
		 *     wp trasloco incremental queue-upload backup.wpress --allow-unencrypted
		 *     wp trasloco incremental queue-download backup.wpress
		 *
		 * Captured snapshots are stored privately under the backup directory. They
		 * remain independently reconstructible and do not depend on source files.
		 * Remote upload stores the original bytes and therefore requires explicit
		 * plaintext-storage consent; download verifies each object before publish.
		 *
		 * @subcommand incremental
		 */
		public function incremental( array $args, array $assoc_args ) {
			$action = isset( $args[0] ) ? $args[0] : '';
			$store_root = rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental';
			require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
			$store = new Trasloco_Incremental_Store( $store_root );
			try {
				if ( $action === 'stats' ) {
					$stats = $store->storage_stats();
					WP_CLI::line( sprintf( 'Snapshots: %d; logical bytes: %d; object bytes: %d; catalog bytes: %d; source archive bytes: %d; manifest bytes: %d; physical bytes: %d; net saved bytes: %d',
						$stats['snapshots'], $stats['logical_bytes'], $stats['object_bytes'], $stats['catalog_bytes'],
						$stats['source_archive_bytes'], $stats['source_manifest_bytes'], $stats['physical_bytes'], $stats['net_saved_bytes'] ) );
					return;
				}
				if ( in_array( $action, array( 'queue-upload', 'queue-download' ), true ) ) {
					$name = isset( $args[1] ) ? $args[1] : '';
					if ( ! is_string( $name ) || strlen( $name ) > 206 || basename( $name ) !== $name ||
						! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) || strpos( $name, '..' ) !== false ) {
						WP_CLI::error( 'Specify a valid backup filename ending in .wpress.' );
					}
					$upload = $action === 'queue-upload';
					if ( $upload && ! isset( $assoc_args['allow-unencrypted'] ) ) {
						WP_CLI::error( 'Incremental objects contain the original backup bytes; use --allow-unencrypted only when remote plaintext storage is acceptable.' );
					}
					$id = Trasloco_Job_Worker::enqueue_incremental_transfer(
						$upload ? 'incremental_upload' : 'incremental_download', $name, $upload
					);
					if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
					WP_CLI::success( sprintf( 'Queued incremental %s job %d for %s.', $upload ? 'upload' : 'download', $id, $name ) );
					return;
				}
				if ( $action === 'upload' || $action === 'download' ) {
					if ( ! wp_mkdir_p( TRASLOCO_BACKUPS_PATH ) ) {
						WP_CLI::error( 'Unable to prepare the local backup directory.' );
					}
					$name = isset( $args[1] ) ? $args[1] : '';
					if ( ! is_string( $name ) || strlen( $name ) > 206 || basename( $name ) !== $name ||
						! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) || strpos( $name, '..' ) !== false ) {
						WP_CLI::error( 'Specify a valid backup filename ending in .wpress.' );
					}
					if ( $action === 'upload' && ! isset( $assoc_args['allow-unencrypted'] ) ) {
						WP_CLI::error( 'Incremental objects contain the original backup bytes; use --allow-unencrypted only when remote plaintext storage is acceptable.' );
					}
					require_once TRASLOCO_LIB_PATH . '/model/storage/class-trasloco-incremental-transfer.php';
					$remote = Trasloco_Storage_Factory::configured();
					$transfer = new Trasloco_Incremental_Transfer( $remote, $store );
					$result = $action === 'upload' ? $transfer->upload( $name ) : $transfer->download( $name );
					WP_CLI::success( sprintf( '%s %s (%d objects, %d bytes uploaded).', $result['archive'],
						$result['status'], isset( $result['objects_uploaded'] ) ? $result['objects_uploaded'] : $result['objects'],
						isset( $result['bytes_uploaded'] ) ? $result['bytes_uploaded'] : 0 ) );
					return;
				}
				if ( $action === 'list' ) {
					$rows = new cli\Table;
					$rows->setHeaders( array( 'archive' => 'Archive', 'date' => 'Captured', 'size' => 'Size', 'objects' => 'Chunks',
						'unique' => 'Unique chunks', 'sha256' => 'SHA-256' ) );
					foreach ( $store->list_snapshots() as $snapshot ) {
						$rows->addRow( array( 'archive' => $snapshot['archive'], 'date' => gmdate( 'Y-m-d H:i:s', $snapshot['mtime'] ) . ' UTC', 'size' => size_format( $snapshot['size_bytes'], 2 ),
							'objects' => $snapshot['objects'], 'unique' => $snapshot['unique_objects'], 'sha256' => $snapshot['sha256'] ) );
					}
					$rows->display();
					return;
				}
				if ( $action === 'capture' || $action === 'verify' || $action === 'materialize' || $action === 'compact' ) {
					$name = isset( $args[1] ) ? $args[1] : '';
					if ( ! is_string( $name ) || basename( $name ) !== $name ||
						! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $name ) || strpos( $name, '..' ) !== false ) {
						WP_CLI::error( 'Specify a valid backup filename ending in .wpress.' );
					}
					if ( $action === 'capture' ) {
						$source = rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $name;
						if ( ! is_file( $source ) || is_link( $source ) ) { WP_CLI::error( 'Backup file is missing or unsafe.' ); }
						require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
						$integrity = Trasloco_Integrity::verify( $source );
						if ( $integrity['status'] !== 'verified' ) {
							WP_CLI::error( 'Only a verified backup can be captured.' );
						}
						$result = $store->capture( $source, $integrity['sha256'] );
						WP_CLI::success( sprintf( 'Captured %s (%s, %d chunks; SHA-256 %s).', $name,
							size_format( $result['size_bytes'], 2 ), $result['objects'], $result['sha256'] ) );
						return;
					}
					if ( $action === 'verify' ) {
						$result = $store->verify( $name );
						WP_CLI::success( sprintf( 'Verified %s (%s; SHA-256 %s).', $name,
							size_format( $result['size_bytes'], 2 ), $result['sha256'] ) );
						return;
					}
					if ( $action === 'compact' ) {
						$lock_handle = false;
						try {
							if ( strpos( $name, 'recovery-' ) === 0 ) { throw new RuntimeException( 'Recovery-point archives cannot be compacted.' ); }
							require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-export-lock.php';
							$lock_owner = bin2hex( random_bytes( 32 ) );
							$lock_handle = Trasloco_Export_Lock::acquire( $lock_owner, HOUR_IN_SECONDS );
							if ( $lock_handle === false ) { throw new RuntimeException( 'Another export is active; compaction was not started.' ); }
							$source = rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $name;
							if ( ! is_file( $source ) || is_link( $source ) ) { throw new RuntimeException( 'Source archive is missing or unsafe.' ); }
							require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
							require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-archive-inspector.php';
							if ( Trasloco_Integrity::verify( $source )['status'] !== 'verified' ) { throw new RuntimeException( 'Archive integrity verification failed; source was retained.' ); }
							$inspection = Trasloco_Archive_Inspector::inspect( $source, 0 );
							if ( empty( $inspection['restore_inputs']['package.json'] ) || empty( $inspection['restore_inputs']['database.sql'] ) ) {
								throw new RuntimeException( 'Archive is missing required restore metadata; source was retained.' );
							}
							$result = $store->compact( $name );
						} catch ( Throwable $error ) {
							if ( is_resource( $lock_handle ) ) { Trasloco_Export_Lock::release( $lock_handle, $lock_owner ); }
							WP_CLI::error( $error->getMessage() );
						}
						if ( is_resource( $lock_handle ) ) { Trasloco_Export_Lock::release( $lock_handle, $lock_owner ); }
						WP_CLI::success( sprintf( 'Compacted %s after catalog, archive checksum and restore-structure verification; removed %d bytes. Restore with wp trasloco restore %s.',
							$name, $result['removed_bytes'], $name ) );
						return;
					}
					$output = isset( $assoc_args['output'] ) ? $assoc_args['output'] : 'restored-' . $name;
					if ( ! is_string( $output ) || basename( $output ) !== $output ||
						! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $output ) || strpos( $output, '..' ) !== false ) {
						WP_CLI::error( 'Output must be a new .wpress filename.' );
					}
					$destination = rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $output;
					$result = $store->materialize( $name, $destination );
					WP_CLI::success( sprintf( 'Reconstructed %s (%s; SHA-256 %s).', $output,
						size_format( $result['size_bytes'], 2 ), $result['sha256'] ) );
					return;
				}
				if ( $action === 'prune' ) {
					if ( empty( $assoc_args['keep'] ) ) {
						WP_CLI::error( 'Use --keep with at least one comma-separated snapshot filename.' );
					}
					$keep = array_values( array_filter( array_map( 'trim', explode( ',', $assoc_args['keep'] ) ), 'strlen' ) );
					$result = $store->prune( $keep );
					WP_CLI::success( sprintf( 'Retained %d snapshots; removed %d snapshots and %d unreferenced chunks.',
						count( $result['retained_snapshots'] ), count( $result['removed_snapshots'] ), count( $result['removed_objects'] ) ) );
					return;
				}
				WP_CLI::error( 'Use capture, verify, materialize, prune, list, stats, compact, upload, or download.' );
			} catch ( Throwable $error ) {
				WP_CLI::error( $error->getMessage() );
			}
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
		 * Prepare and review file recovery for a pending import receipt.
		 *
		 * Run after `restore-recovery`. The command stages the verified snapshot,
		 * preserves current originals and lists archive-absent files; it does not
		 * write to wp-content or approve quarantine.
		 *
		 * ## OPTIONS
		 * <receipt-id>
		 * The 32-character identifier from `php tools/recovery-status.php --json`.
		 * --workspace=<absolute-path>
		 * A new private directory outside wp-content, with an existing parent.
		 *
		 * @subcommand prepare-recovery-files
		 */
		public function prepare_recovery_files( array $args, array $assoc_args ) {
			if ( count( $args ) !== 1 || ! is_string( $args[0] ) ||
				! preg_match( '/^[a-f0-9]{32}$/D', $args[0] ) ||
				empty( $assoc_args['workspace'] ) || ! is_string( $assoc_args['workspace'] ) ||
				$assoc_args['workspace'][0] !== DIRECTORY_SEPARATOR ) {
				WP_CLI::error( 'Usage: wp trasloco prepare-recovery-files <receipt-id> --workspace=/absolute/private/new-directory' );
			}

			require_once TRASLOCO_LIB_PATH . '/model/import/class-trasloco-recovery-journal.php';
			require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
			require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-offline-file-recovery.php';
			$receipt = Trasloco_Recovery_Journal::get( TRASLOCO_BACKUPS_PATH, $args[0] );
			if ( ! is_array( $receipt ) || $receipt['status'] !== 'pending' ) {
				WP_CLI::error( 'The pending recovery receipt was not found.' );
			}
			try {
				$archive = trasloco_backup_path( array( 'archive' => $receipt['recovery_archive'] ) );
				if ( Trasloco_Integrity::verify( $archive )['status'] !== 'verified' ) {
					throw new RuntimeException( 'Recovery archive checksum verification failed.' );
				}
				$prepared = Trasloco_Offline_File_Recovery::prepare(
					$archive,
					WP_CONTENT_DIR,
					$assoc_args['workspace'],
					Trasloco_Offline_File_Recovery::MAX_BYTES,
					true,
					false
				);
				$review = Trasloco_Offline_File_Recovery::review( $assoc_args['workspace'] );
				$review_file = trailingslashit( realpath( $assoc_args['workspace'] ) ) . 'review.json';
				$review_json = wp_json_encode( $review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $review_json ) || file_put_contents( $review_file, $review_json, LOCK_EX ) !== strlen( $review_json ) ||
					! @chmod( $review_file, 0600 ) ) {
					throw new RuntimeException( 'Could not write the private review report.' );
				}
			} catch ( Throwable $error ) {
				WP_CLI::error( 'File recovery plan could not be prepared. No destination files were changed.' );
			}

			$plan_output = wp_json_encode( array(
				'recovery_archive' => $receipt['recovery_archive'],
				'workspace' => realpath( $assoc_args['workspace'] ),
				'file_plan_file' => trailingslashit( realpath( $assoc_args['workspace'] ) ) . 'plan.json',
				'prepared' => $prepared,
				'review' => array(
					'quarantine_candidates' => count( $review['quarantine_files'] ),
					'approval_sha256' => $review['approval_sha256'],
					'report_file' => $review_file,
				),
				'safety_notice' => 'Review every quarantine_files entry in review.json. Apply only while all site writers are stopped, using approval_sha256.',
			), JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $plan_output ) ) {
				WP_CLI::error( 'File recovery plan could not be encoded.' );
			}
			WP_CLI::line( $plan_output );
			fwrite( STDERR, "Review the private review.json report before applying file recovery.\n" );
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
		 *
		 * [--exclude-paths=<paths>]
		 * Skip comma-separated paths relative to wp-content (uploads, plugins, themes, mu-plugins).
		 * Example: wp trasloco restore site.wpress --exclude-paths=uploads/cache,plugins/example/generated
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

			// Snapshot catalogs remain usable after the full archive is explicitly
			// compacted: reconstruct to the original filename without overwriting.
			if ( isset( $args[0] ) && is_string( $args[0] ) &&
				preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]*\.wpress$/D', $args[0] ) &&
				strpos( $args[0], '..' ) === false ) {
				$requested_archive = trasloco_backup_path( array( 'archive' => $args[0] ) );
				if ( ! file_exists( $requested_archive ) && ! is_link( $requested_archive ) ) {
					require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';
					require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
					require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-archive-inspector.php';
					$reconstructed = false;
					$manifest_created = false;
					$reconstructed_stat = null;
					$manifest_stat = null;
					try {
						$store = new Trasloco_Incremental_Store( rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
						$store->verify( $args[0] );
						$store->materialize( $args[0], $requested_archive );
						$reconstructed = true;
						$reconstructed_stat = @lstat( $requested_archive );
						Trasloco_Integrity::create( $requested_archive );
						$manifest_created = true;
						$manifest_stat = @lstat( Trasloco_Integrity::manifest_path( $requested_archive ) );
						$inspection = Trasloco_Archive_Inspector::inspect( $requested_archive, 0 );
						if ( empty( $inspection['restore_inputs']['package.json'] ) || empty( $inspection['restore_inputs']['database.sql'] ) ) {
							throw new RuntimeException( 'Reconstructed archive is missing required restore data.' );
						}
					} catch ( Throwable $error ) {
						$current_archive_stat = @lstat( $requested_archive );
						if ( $reconstructed && is_array( $reconstructed_stat ) && is_array( $current_archive_stat ) &&
							! is_link( $requested_archive ) && (int) $current_archive_stat['dev'] === (int) $reconstructed_stat['dev'] &&
							(int) $current_archive_stat['ino'] === (int) $reconstructed_stat['ino'] ) { @unlink( $requested_archive ); }
						$manifest_path = Trasloco_Integrity::manifest_path( $requested_archive );
						$current_manifest_stat = @lstat( $manifest_path );
						if ( $manifest_created && is_array( $manifest_stat ) && is_array( $current_manifest_stat ) &&
							! is_link( $manifest_path ) && (int) $current_manifest_stat['dev'] === (int) $manifest_stat['dev'] &&
							(int) $current_manifest_stat['ino'] === (int) $manifest_stat['ino'] ) { @unlink( $manifest_path ); }
						WP_CLI::error( 'Snapshot reconstruction or structural verification failed. No restore was started.' );
					}
					WP_CLI::log( 'Verified incremental snapshot reconstructed; starting the standard restore workflow.' );
				}
			}

			$params = array();
			if ( isset( $args[0] ) && is_file( trasloco_backup_path( array( 'archive' => $args[0] ) ) ) ) {
				$params = array(
					'storage'              => trasloco_storage_folder(),
					'archive'              => $args[0],
					'trasloco_manual_restore' => true,
				);
				if ( isset( $assoc_args['exclude-paths'] ) ) {
					if ( is_array( $assoc_args['exclude-paths'] ) ) {
						$raw_excludes = $assoc_args['exclude-paths'];
					} elseif ( is_string( $assoc_args['exclude-paths'] ) ) {
						$raw_excludes = explode( ',', $assoc_args['exclude-paths'] );
					} else {
						WP_CLI::error( 'Excluded paths must be a comma-separated list of wp-content-relative paths.' );
					}
					foreach ( $raw_excludes as $exclude_path ) {
						if ( ! is_string( $exclude_path ) ) {
							WP_CLI::error( 'Excluded paths must be a comma-separated list of wp-content-relative paths.' );
						}
					}
					$params['options']['exclude_paths'] = array_map( 'trim', $raw_excludes );
				}
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
