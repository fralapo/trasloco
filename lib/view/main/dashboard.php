<?php
/**
 * Task-oriented starting point for administrators. No operations run here:
 * export/import/restore still require their own explicit confirmations.
 */
?>
<div class="tr-page tr-dashboard">
	<header class="tr-head">
		<h1><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span> Trasloco</h1>
		<p class="tr-lead"><?php esc_html_e( 'What would you like to do?', TRASLOCO_PLUGIN_NAME ); ?></p>
		<p class="tr-hint"><?php esc_html_e( 'Choose a task. You can review the details before anything changes.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>
	<section class="tr-dashboard-grid" aria-label="<?php echo esc_attr__( 'Tasks', TRASLOCO_PLUGIN_NAME ); ?>">
		<article class="tr-card tr-dashboard-card">
			<span class="dashicons dashicons-migrate" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Move to another site', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p><?php esc_html_e( 'Create an export on the old site, then import it on the destination.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<a class="tr-btn tr-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=trasloco_export' ) ); ?>"><?php esc_html_e( 'Export', TRASLOCO_PLUGIN_NAME ); ?> <span aria-hidden="true">→</span></a>
		</article>
		<article class="tr-card tr-dashboard-card">
			<span class="dashicons dashicons-backup" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Back up this site', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p><?php esc_html_e( 'Save a complete site copy without replacing anything.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<a class="tr-btn tr-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=trasloco_export' ) ); ?>"><?php esc_html_e( 'Create export file', TRASLOCO_PLUGIN_NAME ); ?> <span aria-hidden="true">→</span></a>
		</article>
		<article class="tr-card tr-dashboard-card">
			<span class="dashicons dashicons-upload" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Replace this site', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p><?php esc_html_e( 'Import a .wpress archive. Back up the destination before continuing.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<a class="tr-btn tr-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=trasloco_import' ) ); ?>"><?php esc_html_e( 'Import', TRASLOCO_PLUGIN_NAME ); ?> <span aria-hidden="true">→</span></a>
		</article>
		<article class="tr-card tr-dashboard-card">
			<span class="dashicons dashicons-archive" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Manage saved backups', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p><?php esc_html_e( 'Download, restore or remove archives previously saved on this server.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<a class="tr-btn tr-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=trasloco_backups' ) ); ?>"><?php esc_html_e( 'Backups', TRASLOCO_PLUGIN_NAME ); ?> <span aria-hidden="true">→</span></a>
		</article>
	</section>
	<section class="tr-card" aria-labelledby="tr-dash-activity">
		<h2 id="tr-dash-activity"><?php esc_html_e( 'Recent operations', TRASLOCO_PLUGIN_NAME ); ?></h2>
		<?php $tr_jobs = Trasloco_Job_Store::recent( 10 ); ?>
		<?php if ( empty( $tr_jobs ) ) : ?>
			<p class="tr-hint"><?php esc_html_e( 'No operations have been recorded yet.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Operation', TRASLOCO_PLUGIN_NAME ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', TRASLOCO_PLUGIN_NAME ); ?></th>
					<th scope="col"><?php esc_html_e( 'Progress', TRASLOCO_PLUGIN_NAME ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $tr_jobs as $tr_job ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( ucfirst( $tr_job['kind'] ) ); ?> #<?php echo (int) $tr_job['id']; ?></th>
						<td><?php echo esc_html( ucfirst( $tr_job['state'] ) ); ?>
							<?php if ( ! empty( $tr_job['failure'] ) ) : ?>
								<span class="tr-hint"><?php echo esc_html( $tr_job['failure'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><progress value="<?php echo (int) $tr_job['progress']; ?>" max="100" aria-label="<?php echo esc_attr( sprintf( __( 'Progress for operation %d', TRASLOCO_PLUGIN_NAME ), (int) $tr_job['id'] ) ); ?>"></progress> <?php echo (int) $tr_job['progress']; ?>%</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php $tr_config = Trasloco_Scheduled_Backups::settings(); ?>
	<?php if ( $tr_config['frequency'] !== 'off' && ( $tr_next = wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ) ) ) : ?>
		<p class="tr-hint"><?php echo esc_html( sprintf( __( 'Next scheduled backup: %s', TRASLOCO_PLUGIN_NAME ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $tr_next ) ) ); ?></p>
	<?php endif; ?>
	<p class="tr-dashboard-guide"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php esc_html_e( 'Need help with migration or recovery?', TRASLOCO_PLUGIN_NAME ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=trasloco_guide' ) ); ?>"><?php esc_html_e( 'Guide', TRASLOCO_PLUGIN_NAME ); ?></a></p>
</div>
