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
?>

<div class="tr-page">
	<header class="tr-head">
		<h1><span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e( 'Backups', TRASLOCO_PLUGIN_NAME ); ?></h1>
		<p class="tr-lead"><?php echo wp_kses( sprintf( __( 'Every export is saved on this server, in the %s folder. Here you can download, restore or delete each file.', TRASLOCO_PLUGIN_NAME ), '<code>' . esc_html( TRASLOCO_BACKUPS_PATH ) . '</code>' ), array( 'code' => array() ) ); ?></p>
	</header>
	<?php if ( ! empty( $selective_restore_active ) ) : ?>
		<p role="status" class="notice notice-warning"><?php esc_html_e( 'A selected-table restore is running. Wait for it to finish before starting another import or restore.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php endif; ?>

	<section class="tr-card" aria-labelledby="tr-automatic-backups">
		<h2 id="tr-automatic-backups"><?php esc_html_e( 'Automatic backups', TRASLOCO_PLUGIN_NAME ); ?></h2>
		<p class="tr-hint"><?php esc_html_e( 'Create verified backups on a schedule and automatically retain the newest copies.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php if ( isset( $_GET['schedule_saved'] ) && $_GET['schedule_saved'] === '1' ) : ?>
			<p role="status"><?php esc_html_e( 'Backup schedule saved.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php endif; ?>
		<?php $tr_schedule = Trasloco_Scheduled_Backups::settings(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="trasloco_save_schedule" />
			<?php wp_nonce_field( 'trasloco_save_schedule' ); ?>
			<p><label for="tr-schedule-frequency"><strong><?php esc_html_e( 'Frequency', TRASLOCO_PLUGIN_NAME ); ?></strong></label></p>
			<p><select id="tr-schedule-frequency" name="frequency">
				<option value="off" <?php selected( $tr_schedule['frequency'], 'off' ); ?>><?php esc_html_e( 'Disabled', TRASLOCO_PLUGIN_NAME ); ?></option>
				<option value="hourly" <?php selected( $tr_schedule['frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', TRASLOCO_PLUGIN_NAME ); ?></option>
				<option value="daily" <?php selected( $tr_schedule['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', TRASLOCO_PLUGIN_NAME ); ?></option>
				<option value="weekly" <?php selected( $tr_schedule['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', TRASLOCO_PLUGIN_NAME ); ?></option>
				<option value="monthly" <?php selected( $tr_schedule['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', TRASLOCO_PLUGIN_NAME ); ?></option>
			</select></p>
			<p><label for="tr-schedule-keep"><strong><?php esc_html_e( 'Keep latest backups', TRASLOCO_PLUGIN_NAME ); ?></strong></label></p>
			<p><input id="tr-schedule-keep" type="number" name="keep" min="1" max="100" required value="<?php echo esc_attr( $tr_schedule['keep'] ); ?>" /></p>
			<p><label><input type="checkbox" name="incremental_snapshots" value="1" <?php checked( $tr_schedule['incremental_snapshots'] ); ?> /> <?php esc_html_e( 'Store scheduled backups as local incremental snapshots', TRASLOCO_PLUGIN_NAME ); ?></label></p>
			<p class="tr-hint"><?php esc_html_e( 'Snapshots use a private local content store and follow the scheduled retention count. Reconstruct one with wp trasloco incremental materialize before using the existing restore workflow. This does not upload snapshots or reduce disk usage while the regular .wpress archives remain.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p><label><input type="checkbox" name="upload_offsite" value="1" <?php checked( $tr_schedule['upload_offsite'] ); ?> /> <?php esc_html_e( 'Encrypt and upload each scheduled backup to remote storage', TRASLOCO_PLUGIN_NAME ); ?></label></p>
			<p class="tr-hint"><?php esc_html_e( 'Requires configured WebDAV or S3-compatible storage and TRASLOCO_ARCHIVE_PASSPHRASE in the worker environment or wp-config.php. S3 also requires its optional runtime and PHP 8.1 or later. The passphrase is never stored in WordPress options or job records.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p><label><input type="checkbox" name="before_updates" value="1" <?php checked( $tr_schedule['before_updates'] ); ?> /> <?php esc_html_e( 'Create a verified recovery point before updating WordPress, plugins or themes', TRASLOCO_PLUGIN_NAME ); ?></label></p>
			<p class="tr-hint"><?php esc_html_e( 'If a safety backup fails, Trasloco stops the update. A recovery point is not an automatic rollback.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p><label><input type="checkbox" name="notify_alerts" value="1" <?php checked( $tr_schedule['notify_alerts'] ); ?> /> <?php esc_html_e( 'Email me about scheduled backup failures and offsite storage status changes', TRASLOCO_PLUGIN_NAME ); ?></label></p>
			<p><label for="tr-notification-email"><strong><?php esc_html_e( 'Alert email address', TRASLOCO_PLUGIN_NAME ); ?></strong></label></p>
			<p><input id="tr-notification-email" type="email" name="notification_email" autocomplete="email" required value="<?php echo esc_attr( $tr_schedule['notification_email'] ); ?>" /></p>
			<p><label for="tr-notification-webhook"><strong><?php esc_html_e( 'Webhook URL (HTTPS)', TRASLOCO_PLUGIN_NAME ); ?></strong></label></p>
			<p><input id="tr-notification-webhook" type="url" name="webhook_url" value="<?php echo esc_attr( $tr_schedule['webhook_url'] ); ?>" placeholder="https://" /></p>
			<p><label for="tr-notification-webhook-secret"><strong><?php esc_html_e( 'Webhook signing secret', TRASLOCO_PLUGIN_NAME ); ?></strong></label></p>
			<p><input id="tr-notification-webhook-secret" type="password" name="webhook_secret" autocomplete="new-password" value="" /></p>
			<p class="tr-hint"><?php esc_html_e( 'Use a secret of 32 to 128 characters. Leave it empty to keep the saved secret. Webhook requests contain only the event and delivery metadata and include an HMAC-SHA-256 signature.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'Failed email deliveries are retried up to three times. Delivery depends on WordPress mail and cron configuration.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'WordPress scheduled tasks depend on site traffic. Configure system cron for reliable timing on low-traffic sites.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save schedule', TRASLOCO_PLUGIN_NAME ); ?></button></p>
		</form>
	</section>

	<?php if ( isset( $_GET['trasloco_verify_status'] ) && $_GET['trasloco_verify_status'] === 'queued' ) : ?>
		<p role="status" class="notice notice-success"><?php esc_html_e( 'Backup verification queued. View the recent jobs below for the result.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php elseif ( isset( $_GET['trasloco_verify_status'] ) && $_GET['trasloco_verify_status'] === 'error' ) : ?>
		<p role="alert" class="notice notice-error"><?php esc_html_e( 'Backup verification could not be queued.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php endif; ?>
	<?php
	$tr_archive_passphrase = defined( 'TRASLOCO_ARCHIVE_PASSPHRASE' )
		? TRASLOCO_ARCHIVE_PASSPHRASE : getenv( 'TRASLOCO_ARCHIVE_PASSPHRASE' );
	$tr_manual_encryption_enabled = is_string( $tr_archive_passphrase ) && strlen( $tr_archive_passphrase ) >= 12 &&
		function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' );
	?>
	<?php if ( isset( $_GET['trasloco_encrypt_status'] ) && $_GET['trasloco_encrypt_status'] === 'queued' && isset( $_GET['trasloco_crypto_job'], $_GET['trasloco_crypto_output'] ) ) : ?>
		<p role="status" aria-live="polite" class="notice notice-info tr-crypto-job-notice" data-crypto-job-id="<?php echo esc_attr( absint( $_GET['trasloco_crypto_job'] ) ); ?>" data-crypto-operation="encrypt" data-crypto-output="<?php echo esc_attr( sanitize_file_name( wp_unslash( $_GET['trasloco_crypto_output'] ) ) ); ?>"><?php esc_html_e( 'Encryption queued. Preparing the verified backup in bounded batches…', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php elseif ( isset( $_GET['trasloco_encrypt_status'] ) && $_GET['trasloco_encrypt_status'] === 'error' ) : ?>
		<p role="alert" class="notice notice-error"><?php esc_html_e( 'Backup encryption failed. Check the encryption setup and backup integrity, then try again.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php elseif ( isset( $_GET['trasloco_encrypt_status'] ) && $_GET['trasloco_encrypt_status'] === 'complete' && isset( $_GET['trasloco_encrypted_archive'] ) ) : ?>
		<?php
		require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
		$tr_encrypted_name = is_string( $_GET['trasloco_encrypted_archive'] )
			? wp_unslash( $_GET['trasloco_encrypted_archive'] ) : '';
		$tr_encrypted_path = '';
		try { $tr_encrypted_path = trasloco_encrypted_backup_path( $tr_encrypted_name ); } catch ( Exception $ignored ) {}
		?>
		<?php if ( $tr_encrypted_path && is_file( $tr_encrypted_path ) && ! is_link( $tr_encrypted_path ) &&
			is_file( Trasloco_Integrity::manifest_path( $tr_encrypted_path ) ) ) : ?>
			<p role="status" class="notice notice-success"><?php esc_html_e( 'Encrypted backup created. Download it now and store your passphrase separately.', TRASLOCO_PLUGIN_NAME ); ?>
				<a href="<?php echo esc_url( trasloco_encrypted_backup_url( $tr_encrypted_name ) ); ?>"><?php esc_html_e( 'Download encrypted backup', TRASLOCO_PLUGIN_NAME ); ?></a>
			</p>
		<?php else : ?>
			<p role="alert" class="notice notice-error"><?php esc_html_e( 'The encrypted backup is not available for download.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( isset( $_GET['trasloco_decrypt_status'] ) && $_GET['trasloco_decrypt_status'] === 'queued' && isset( $_GET['trasloco_crypto_job'], $_GET['trasloco_crypto_output'] ) ) : ?>
		<p role="status" aria-live="polite" class="notice notice-info tr-crypto-job-notice" data-crypto-job-id="<?php echo esc_attr( absint( $_GET['trasloco_crypto_job'] ) ); ?>" data-crypto-operation="decrypt" data-crypto-output="<?php echo esc_attr( sanitize_file_name( wp_unslash( $_GET['trasloco_crypto_output'] ) ) ); ?>"><?php esc_html_e( 'Decryption queued. Authenticating the encrypted backup in bounded batches…', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php elseif ( isset( $_GET['trasloco_decrypt_status'] ) && $_GET['trasloco_decrypt_status'] === 'error' ) : ?>
		<p role="alert" class="notice notice-error"><?php esc_html_e( 'Backup decryption failed. Check the passphrase configuration and encrypted archive integrity, then try again.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php elseif ( isset( $_GET['trasloco_decrypt_status'] ) && $_GET['trasloco_decrypt_status'] === 'complete' && isset( $_GET['trasloco_decrypted_archive'] ) ) : ?>
		<?php
		require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
		$tr_decrypted_name = is_string( $_GET['trasloco_decrypted_archive'] )
			? wp_unslash( $_GET['trasloco_decrypted_archive'] ) : '';
		$tr_decrypted_path = '';
		try { if ( $tr_decrypted_name !== '' ) { $tr_decrypted_path = trasloco_backup_path( array( 'archive' => $tr_decrypted_name ) ); } } catch ( Exception $ignored ) {}
		?>
		<?php if ( $tr_decrypted_path && is_file( $tr_decrypted_path ) && ! is_link( $tr_decrypted_path ) &&
			is_file( Trasloco_Integrity::manifest_path( $tr_decrypted_path ) ) ) : ?>
			<p role="status" class="notice notice-success"><?php esc_html_e( 'Decrypted backup created in private storage. Download it, then secure or remove the plaintext copy.', TRASLOCO_PLUGIN_NAME ); ?>
				<a href="<?php echo esc_url( trasloco_backup_url( array( 'archive' => $tr_decrypted_name ) ) ); ?>"><?php esc_html_e( 'Download decrypted backup', TRASLOCO_PLUGIN_NAME ); ?></a>
			</p>
		<?php else : ?>
			<p role="alert" class="notice notice-error"><?php esc_html_e( 'The decrypted backup is not available for download.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( ! $tr_manual_encryption_enabled ) : ?>
		<p class="tr-hint"><?php esc_html_e( 'To encrypt a backup here, configure TRASLOCO_ARCHIVE_PASSPHRASE (at least 12 characters) in the worker environment or wp-config.php and enable PHP sodium.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php else : ?>
		<p class="tr-hint"><?php esc_html_e( 'Encryption creates a separate file. The original backup stays unencrypted; protect or remove it yourself after checking the encrypted copy.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<p class="tr-hint"><?php esc_html_e( 'Encryption and decryption run on the server and may take several minutes. Keep this page open and allow free disk space for another archive copy.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php endif; ?>

	<?php
    $tr_pending_recoveries = array();
    $tr_recovery_error = false;
    try {
        if ( is_dir( TRASLOCO_BACKUPS_PATH ) ) {
            foreach ( Trasloco_Recovery_Journal::entries( TRASLOCO_BACKUPS_PATH, 30 ) as $tr_receipt ) {
                if ( $tr_receipt['status'] === 'pending' ) {
                    $tr_pending_recoveries[] = $tr_receipt;
                }
            }
        }
    } catch ( Throwable $tr_error ) {
        $tr_recovery_error = true;
    }
    ?>
    <?php if ( $tr_pending_recoveries || $tr_recovery_error ) : ?>
        <section class="tr-card" aria-labelledby="tr-recovery-attention">
            <h2 id="tr-recovery-attention"><?php esc_html_e( 'Recovery points requiring attention', TRASLOCO_PLUGIN_NAME ); ?></h2>
            <p class="tr-hint"><?php esc_html_e( 'A previous import did not confirm completion. Keep its recovery archive, check the site, and recover manually if needed.', TRASLOCO_PLUGIN_NAME ); ?></p>
            <?php if ( $tr_recovery_error ) : ?>
                <p role="alert"><?php esc_html_e( 'Recovery receipts could not be read.', TRASLOCO_PLUGIN_NAME ); ?></p>
            <?php endif; ?>
            <?php if ( $tr_pending_recoveries ) : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e( 'Created', TRASLOCO_PLUGIN_NAME ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Imported archive', TRASLOCO_PLUGIN_NAME ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Recovery archive', TRASLOCO_PLUGIN_NAME ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $tr_pending_recoveries as $tr_receipt ) : ?>
                        <tr>
                            <td><?php echo esc_html( $tr_receipt['created_at'] ); ?> UTC</td>
                            <td><code><?php echo esc_html( $tr_receipt['source_archive'] ); ?></code></td>
                            <td><code><?php echo esc_html( $tr_receipt['recovery_archive'] ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p class="tr-hint"><?php esc_html_e( 'A recovery point is not an automatic rollback. Rehearse restoration on a separate site before replacing production data.', TRASLOCO_PLUGIN_NAME ); ?></p>
        </section>
    <?php endif; ?>

	<?php include TRASLOCO_TEMPLATES_PATH . '/backups/legend.php'; ?>

				<form action="" method="post" id="trasloco-backups-form" class="trasloco-clear">

					<?php if ( is_readable( TRASLOCO_BACKUPS_PATH ) && is_writable( TRASLOCO_BACKUPS_PATH ) ) : ?>
						<?php if ( $backups ) : ?>
							<?php $tr_verification_statuses = Trasloco_Job_Store::verification_statuses( array_column( $backups, 'filename' ) ); ?>
							<table class="trasloco-backups tr-table">
								<thead>
									<tr>
										<th class="trasloco-column-name"><?php _e( 'Name', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th class="trasloco-column-date"><?php _e( 'Date', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th class="trasloco-column-size"><?php _e( 'Size', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th><?php esc_html_e( 'Integrity', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th class="trasloco-column-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', TRASLOCO_PLUGIN_NAME ); ?></span></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $backups as $backup ) : ?>
						<?php $tr_still_verified = false; ?>
									<tr>
										<th scope="row" class="trasloco-column-name">
											<?php if ( $backup['path'] ) : ?>
												<i class="trasloco-icon-folder" aria-hidden="true"></i>
												<?php echo esc_html( $backup['path'] ); ?>
												<br />
											<?php endif; ?>
											<i class="trasloco-icon-file-zip" aria-hidden="true"></i>
											<?php echo esc_html( basename( $backup['filename'] ) ); ?>
										</th>
										<td class="trasloco-column-date">
											<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['mtime'] ) ); ?>
										</td>
										<td class="trasloco-column-size">
											<?php if ( is_null( $backup['size'] ) ) : ?>
												<?php _e( 'over 2 GB', TRASLOCO_PLUGIN_NAME ); ?>
											<?php else : ?>
												<?php echo size_format( $backup['size'], 2 ); ?>
											<?php endif; ?>
										</td>
										<td><?php
											require_once TRASLOCO_LIB_PATH . '/vendor/core/archiver/class-trasloco-integrity.php';
											$archive_file = trasloco_backup_path( array( 'archive' => $backup['filename'] ) );
											$manifest_file = Trasloco_Integrity::manifest_path( $archive_file );
											$tr_verification = isset( $tr_verification_statuses[ $backup['filename'] ] )
												? $tr_verification_statuses[ $backup['filename'] ] : null;
											if ( ! is_file( $manifest_file ) ) {
												esc_html_e( 'Not verified', TRASLOCO_PLUGIN_NAME );
											} elseif ( $tr_verification && in_array( $tr_verification['state'], array( 'pending', 'running' ), true ) ) {
												esc_html_e( 'Verification in progress', TRASLOCO_PLUGIN_NAME );
											} elseif ( $tr_verification && $tr_verification['state'] === 'failed' ) {
												esc_html_e( 'Verification failed', TRASLOCO_PLUGIN_NAME );
											} else {
												$proof = $tr_verification && $tr_verification['state'] === 'completed'
													? $tr_verification['proof'] : array();
												clearstatcache( true, $archive_file );
												$archive_stat = @lstat( $archive_file );
												clearstatcache( true, $manifest_file );
												$manifest_stat = @lstat( $manifest_file );
												$still_verified = isset( $proof['phase'], $proof['size_bytes'], $proof['file_mtime'], $proof['file_ctime'],
													$proof['file_inode'], $proof['file_device'], $proof['manifest_size_bytes'],
													$proof['manifest_mtime'], $proof['manifest_ctime'], $proof['manifest_inode'], $proof['manifest_device'] ) &&
													is_array( $archive_stat ) && ! is_link( $archive_file ) && is_file( $archive_file ) &&
													is_array( $manifest_stat ) && ! is_link( $manifest_file ) && is_file( $manifest_file ) &&
													$proof['phase'] === 'verified' &&
													(int) $archive_stat['size'] === (int) $proof['size_bytes'] &&
													(int) $archive_stat['mtime'] === (int) $proof['file_mtime'] &&
													(int) $archive_stat['ctime'] === (int) $proof['file_ctime'] &&
													(int) $archive_stat['ino'] === (int) $proof['file_inode'] &&
													(int) $archive_stat['dev'] === (int) $proof['file_device'] &&
													(int) $manifest_stat['size'] === (int) $proof['manifest_size_bytes'] &&
													(int) $manifest_stat['mtime'] === (int) $proof['manifest_mtime'] &&
													(int) $manifest_stat['ctime'] === (int) $proof['manifest_ctime'] &&
													(int) $manifest_stat['ino'] === (int) $proof['manifest_inode'] &&
													(int) $manifest_stat['dev'] === (int) $proof['manifest_device'];
												if ( $still_verified ) {
													$tr_still_verified = true;
													esc_html_e( 'Verified', TRASLOCO_PLUGIN_NAME );
												} else {
													// A manifest alone is not proof of integrity. Avoid hashing
													// large archives during the backup-list request.
													esc_html_e( 'Manifest available (not verified)', TRASLOCO_PLUGIN_NAME );
												}
											}
										?></td>
										<td class="trasloco-column-actions trasloco-backup-actions">
											<?php
											$tr_encrypted_filename = $backup['filename'] . '.enc';
											$tr_encrypted_file = '';
											try { $tr_encrypted_file = trasloco_encrypted_backup_path( $tr_encrypted_filename ); } catch ( Exception $ignored ) {}
											if ( $tr_encrypted_file && is_file( $tr_encrypted_file ) && ! is_link( $tr_encrypted_file ) &&
												is_file( Trasloco_Integrity::manifest_path( $tr_encrypted_file ) ) ) :
										?>
											<a href="<?php echo esc_url( trasloco_encrypted_backup_url( $tr_encrypted_filename ) ); ?>" class="tr-btn tr-btn-ghost" aria-label="<?php echo esc_attr( sprintf( __( 'Download encrypted backup %s', TRASLOCO_PLUGIN_NAME ), basename( $tr_encrypted_filename ) ) ); ?>">
												<span><?php esc_html_e( 'Encrypted', TRASLOCO_PLUGIN_NAME ); ?></span>
											</a>
											<?php if ( $tr_manual_encryption_enabled ) : ?>
												<button type="submit" name="archive" value="<?php echo esc_attr( $tr_encrypted_filename ); ?>" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=trasloco_decrypt_backup' ) ); ?>" formmethod="post" class="tr-btn tr-btn-ghost" aria-label="<?php echo esc_attr( sprintf( __( 'Decrypt backup %s', TRASLOCO_PLUGIN_NAME ), basename( $tr_encrypted_filename ) ) ); ?>">
													<span><?php esc_html_e( 'Decrypt', TRASLOCO_PLUGIN_NAME ); ?></span>
												</button>
											<?php endif; ?>
										<?php endif; ?>
										<?php if ( $tr_manual_encryption_enabled ) : ?>
											<button type="submit" name="archive" value="<?php echo esc_attr( $backup['filename'] ); ?>" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=trasloco_encrypt_backup' ) ); ?>" formmethod="post" class="tr-btn tr-btn-ghost" aria-label="<?php echo esc_attr( sprintf( __( 'Encrypt backup %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
												<span><?php esc_html_e( 'Encrypt', TRASLOCO_PLUGIN_NAME ); ?></span>
											</button>
										<?php endif; ?>
											<button type="submit" name="archive" value="<?php echo esc_attr( $backup['filename'] ); ?>" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=trasloco_verify_archive' ) ); ?>" formmethod="post" class="tr-btn tr-btn-ghost" aria-label="<?php echo esc_attr( sprintf( __( 'Verify %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
											<span><?php esc_html_e( 'Verify', TRASLOCO_PLUGIN_NAME ); ?></span>
										</button>
										<a href="<?php echo esc_url( trasloco_backup_url( array( 'archive' => $backup['filename'] ) ) ); ?>" class="tr-btn tr-btn-ghost trasloco-backup-download" aria-label="<?php echo esc_attr( sprintf( __( 'Download %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
												<i class="trasloco-icon-arrow-down" aria-hidden="true"></i>
												<span><?php _e( 'Download', TRASLOCO_PLUGIN_NAME ); ?></span>
											</a>
											<button type="button" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" class="tr-btn tr-btn-ghost trasloco-backup-restore" aria-label="<?php echo esc_attr( sprintf( __( 'Restore %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
												<i class="trasloco-icon-cloud-upload" aria-hidden="true"></i>
												<span><?php _e( 'Restore', TRASLOCO_PLUGIN_NAME ); ?></span>
											</button>
											<?php if ( $tr_still_verified && ! is_multisite() ) : ?>
												<button type="button" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" class="tr-btn tr-btn-ghost trasloco-backup-selective-restore" aria-label="<?php echo esc_attr( sprintf( __( 'Restore selected tables from %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
													<span><?php esc_html_e( 'Restore tables', TRASLOCO_PLUGIN_NAME ); ?></span>
												</button>
											<?php endif; ?>
											<button type="button" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" class="tr-btn tr-btn-danger trasloco-backup-delete" aria-label="<?php echo esc_attr( sprintf( __( 'Delete %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
												<i class="trasloco-icon-close" aria-hidden="true"></i>
												<span><?php _e( 'Delete', TRASLOCO_PLUGIN_NAME ); ?></span>
											</button>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<div class="trasloco-backups-create">
							<p class="trasloco-backups-empty <?php echo $backups ? 'trasloco-hide' : null; ?>">
								<?php esc_html_e( 'There are no backups on this site yet. A backup is saved here every time you create an export file.', TRASLOCO_PLUGIN_NAME ); ?>
							</p>
							<p>
								<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=trasloco_export' ) ); ?>" class="tr-btn tr-btn-primary">
									<i class="trasloco-icon-export" aria-hidden="true"></i>
									<?php _e( 'Create export file', TRASLOCO_PLUGIN_NAME ); ?>
								</a>
							</p>
						</div>
					<?php else : ?>
						<div class="tr-note tr-note-error" role="alert">
							<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<p><?php printf( wp_kses( __( '<strong>Trasloco cannot save backups on this server.</strong> Ask your hosting company to let the web server write in the folder <code>%s</code>.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array(), 'code' => array() ) ), esc_html( TRASLOCO_BACKUPS_PATH ) ); ?></p>
						</div>
					<?php endif; ?>

					<?php do_action( 'trasloco_backups_left_end' ); ?>

					<?php wp_nonce_field( 'trasloco_verify_archive', 'trasloco_verify_nonce' ); ?>
					<?php wp_nonce_field( 'trasloco_encrypt_backup', 'trasloco_encrypt_nonce' ); ?>
					<?php wp_nonce_field( 'trasloco_decrypt_backup', 'trasloco_decrypt_nonce' ); ?>
					<input type="hidden" name="trasloco_manual_restore" value="1" />

				</form>

	<dialog id="trasloco-selective-restore-dialog" aria-labelledby="trasloco-selective-restore-title">
		<form method="dialog" class="tr-selective-restore-dialog">
			<h2 id="trasloco-selective-restore-title"><?php esc_html_e( 'Restore selected database tables', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p class="tr-selective-restore-status" role="status" aria-live="polite"></p>
			<div class="tr-selective-restore-table-wrap">
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Select', TRASLOCO_PLUGIN_NAME ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Backup table', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Site table', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expected action', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Row changes (raw)', TRASLOCO_PLUGIN_NAME ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>
			<p class="tr-hint"><?php esc_html_e( 'Selected tables are replaced from the backup. Pause writes to the affected parts of your site first. A verified recovery point is created before changes are applied.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'Row comparison uses raw backup values. The importer may transform URLs, serialized values and table prefixes, so the comparison is advisory and does not predict the imported result.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p><label><input type="checkbox" class="tr-selective-restore-confirm" /> <?php esc_html_e( 'I understand that selected tables will be replaced and have paused writes to them.', TRASLOCO_PLUGIN_NAME ); ?></label></p>
			<div class="tr-selective-restore-actions">
				<button type="button" class="button tr-selective-restore-cancel"><?php esc_html_e( 'Cancel', TRASLOCO_PLUGIN_NAME ); ?></button>
				<button type="button" class="button button-primary tr-selective-restore-submit" disabled><?php esc_html_e( 'Restore selected tables', TRASLOCO_PLUGIN_NAME ); ?></button>
			</div>
		</form>
	</dialog>

	<?php include TRASLOCO_TEMPLATES_PATH . '/backups/safety.php'; ?>
</div>
