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
	<p class="tr-dashboard-guide"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php esc_html_e( 'Need help with migration or recovery?', TRASLOCO_PLUGIN_NAME ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=trasloco_guide' ) ); ?>"><?php esc_html_e( 'Guide', TRASLOCO_PLUGIN_NAME ); ?></a></p>
</div>
