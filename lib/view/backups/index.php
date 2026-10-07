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
		<p class="tr-lead"><?php echo wp_kses( sprintf( __( 'Every export is saved on this server, in the %s folder. Here you can download, restore or delete each file.', TRASLOCO_PLUGIN_NAME ), '<code>' . esc_html( basename( WP_CONTENT_DIR ) . '/' . basename( TRASLOCO_BACKUPS_PATH ) ) . '</code>' ), array( 'code' => array() ) ); ?></p>
	</header>

	<?php include TRASLOCO_TEMPLATES_PATH . '/backups/legend.php'; ?>

				<form action="" method="post" id="trasloco-backups-form" class="trasloco-clear">

					<?php if ( is_readable( TRASLOCO_BACKUPS_PATH ) && is_writable( TRASLOCO_BACKUPS_PATH ) ) : ?>
						<?php if ( $backups ) : ?>
							<table class="trasloco-backups tr-table">
								<thead>
									<tr>
										<th class="trasloco-column-name"><?php _e( 'Name', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th class="trasloco-column-date"><?php _e( 'Date', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th class="trasloco-column-size"><?php _e( 'Size', TRASLOCO_PLUGIN_NAME ); ?></th>
										<th class="trasloco-column-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', TRASLOCO_PLUGIN_NAME ); ?></span></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $backups as $backup ) : ?>
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
										<td class="trasloco-column-actions trasloco-backup-actions">
											<a href="<?php echo trasloco_backup_url( array( 'archive' => esc_attr( $backup['filename'] ) ) ); ?>" class="tr-btn tr-btn-ghost trasloco-backup-download" aria-label="<?php echo esc_attr( sprintf( __( 'Download %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
												<i class="trasloco-icon-arrow-down" aria-hidden="true"></i>
												<span><?php _e( 'Download', TRASLOCO_PLUGIN_NAME ); ?></span>
											</a>
											<button type="button" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>" class="tr-btn tr-btn-ghost trasloco-backup-restore" aria-label="<?php echo esc_attr( sprintf( __( 'Restore %s', TRASLOCO_PLUGIN_NAME ), basename( $backup['filename'] ) ) ); ?>">
												<i class="trasloco-icon-cloud-upload" aria-hidden="true"></i>
												<span><?php _e( 'Restore', TRASLOCO_PLUGIN_NAME ); ?></span>
											</button>
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

					<input type="hidden" name="trasloco_manual_restore" value="1" />

				</form>

	<?php include TRASLOCO_TEMPLATES_PATH . '/backups/safety.php'; ?>
</div>
