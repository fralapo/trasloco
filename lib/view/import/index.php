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
		<h1><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Import a site', TRASLOCO_PLUGIN_NAME ); ?></h1>
		<p class="tr-lead"><?php esc_html_e( 'Replace this site with the copy saved in a .wpress file. You create that file on the old site, in Trasloco → Export.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>

	<?php $tr_step = 2; include TRASLOCO_TEMPLATES_PATH . '/main/steps.php'; ?>

	<section class="tr-card tr-card-warn" aria-labelledby="tr-import-before">
		<h2 id="tr-import-before"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'Before you start', TRASLOCO_PLUGIN_NAME ); ?></h2>
		<ul class="tr-checklist">
			<li>
				<strong><?php esc_html_e( 'Back up this site.', TRASLOCO_PLUGIN_NAME ); ?></strong>
				<?php esc_html_e( 'The import replaces everything here: posts, pages, media, users, themes, plugins and settings. It cannot be undone.', TRASLOCO_PLUGIN_NAME ); ?>
				<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=trasloco_export' ) ); ?>"><?php esc_html_e( 'Create a backup of this site', TRASLOCO_PLUGIN_NAME ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Have the login of the old site at hand.', TRASLOCO_PLUGIN_NAME ); ?></strong>
				<?php esc_html_e( 'The users are replaced too: after the import you log in with the username and password that you used on the old site.', TRASLOCO_PLUGIN_NAME ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Check the free space on the new hosting.', TRASLOCO_PLUGIN_NAME ); ?></strong>
				<?php esc_html_e( 'The upload and the restore need free space about twice the size of the file. The control panel of your hosting shows how much is free.', TRASLOCO_PLUGIN_NAME ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Keep this tab open until the end.', TRASLOCO_PLUGIN_NAME ); ?></strong>
				<?php esc_html_e( 'The upload and the restore run in this tab. Closing it stops the import halfway.', TRASLOCO_PLUGIN_NAME ); ?>
			</li>
		</ul>
	</section>

	<section class="tr-card" aria-labelledby="tr-server-readiness">
		<h2 id="tr-server-readiness"><?php esc_html_e( 'Server readiness', TRASLOCO_PLUGIN_NAME ); ?></h2>
		<ul class="tr-list">
			<li><?php esc_html_e( 'PHP version', TRASLOCO_PLUGIN_NAME ); ?>: <strong><?php echo esc_html( PHP_VERSION ); ?></strong></li>
			<li><?php esc_html_e( 'Available disk space', TRASLOCO_PLUGIN_NAME ); ?>: <strong><?php
				$free_space = @disk_free_space( WP_CONTENT_DIR );
				echo $free_space === false ? esc_html__( 'Unknown', TRASLOCO_PLUGIN_NAME ) : esc_html( size_format( $free_space ) );
			?></strong></li>
			<li><?php esc_html_e( 'MySQLi extension', TRASLOCO_PLUGIN_NAME ); ?>: <strong><?php echo extension_loaded( 'mysqli' ) ? esc_html__( 'Available', TRASLOCO_PLUGIN_NAME ) : esc_html__( 'Unavailable', TRASLOCO_PLUGIN_NAME ); ?></strong></li>
		</ul>
		<p class="tr-hint"><?php esc_html_e( 'Trasloco checks the uploaded archive and destination storage again before asking to replace the site.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</section>

	<form action="" method="post" id="trasloco-import-form" class="tr-form" enctype="multipart/form-data">

		<?php do_action( 'trasloco_import_left_options' ); ?>

		<?php include TRASLOCO_TEMPLATES_PATH . '/import/import-buttons.php'; ?>

		<input type="hidden" name="trasloco_manual_import" value="1" />
	</form>

	<?php do_action( 'trasloco_import_left_end' ); ?>

	<details class="tr-details tr-card">
		<summary><?php esc_html_e( 'What happens during the import', TRASLOCO_PLUGIN_NAME ); ?></summary>
		<?php include TRASLOCO_TEMPLATES_PATH . '/import/steps-list.php'; ?>
	</details>
</div>
