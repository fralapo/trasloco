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
		<h1><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export site', TRASLOCO_PLUGIN_NAME ); ?></h1>
		<p class="tr-lead"><?php esc_html_e( 'Create one export file (its name ends in .wpress) with a complete copy of this site. Use it to move the site, or keep it as a backup. On the new site, upload it in Trasloco → Import.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>

	<?php $tr_step = 1; include TRASLOCO_TEMPLATES_PATH . '/main/steps.php'; ?>

	<form action="" method="post" id="trasloco-export-form" class="tr-form">

		<section class="tr-card" aria-labelledby="tr-export-what">
			<h2 id="tr-export-what"><?php esc_html_e( 'What goes into the file', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p><?php esc_html_e( 'If you change nothing, the file contains the whole site:', TRASLOCO_PLUGIN_NAME ); ?></p>
			<ul class="tr-list">
				<li><?php echo wp_kses( __( '<strong>Database</strong>: posts, pages, comments, users, menus and settings.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
				<li><?php echo wp_kses( __( '<strong>Media library</strong>: images, videos, PDFs and every other uploaded file.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
				<li><?php echo wp_kses( __( '<strong>Themes and plugins</strong>, also the ones that are switched off.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
			</ul>
			<p class="tr-hint"><?php esc_html_e( 'Also in the file: everything else in the wp-content folder, for example the backups made by other backup plugins. They can make the file much larger: delete the old ones there first if you do not need them.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'Never in the file: WordPress itself, Trasloco, and the wp-config.php file (where WordPress keeps the password of its database). So the new site needs its own WordPress with Trasloco installed, and it keeps its own database password.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<?php include TRASLOCO_TEMPLATES_PATH . '/export/advanced-settings.php'; ?>
		</section>

		<section class="tr-card" aria-labelledby="tr-export-replace">
			<h2 id="tr-export-replace"><?php esc_html_e( 'Find and replace text', TRASLOCO_PLUGIN_NAME ); ?> <span class="tr-badge"><?php esc_html_e( 'optional', TRASLOCO_PLUGIN_NAME ); ?></span></h2>
			<p class="tr-hint"><?php esc_html_e( 'You do not need this to move the site. When the file is imported, Trasloco changes the old address of the site to the new one by itself: in links, images and settings.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'Use it for other text that must change on the new site, such as an old phone number or a second domain. Every exact match in the database is replaced when the file is imported, also inside settings saved by plugins.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'Capital letters count: "Shop" and "shop" are different texts. Add one row for each text, with Add another replacement.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<?php include TRASLOCO_TEMPLATES_PATH . '/export/find-replace.php'; ?>
		</section>

		<?php do_action( 'trasloco_export_left_options' ); ?>

		<div class="tr-actions">
			<button type="button" id="trasloco-export-file" class="tr-btn tr-btn-primary">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Create export file', TRASLOCO_PLUGIN_NAME ); ?>
			</button>
			<p class="tr-hint"><?php esc_html_e( 'A large site can take several minutes. Keep this tab open: at the end you can download the file, and a copy stays in Trasloco → Backups. The server needs free space about as large as the site, because the file is first saved here.', TRASLOCO_PLUGIN_NAME ); ?></p>
		</div>

		<input type="hidden" name="trasloco_manual_export" value="1" />
	</form>

	<?php do_action( 'trasloco_export_left_end' ); ?>
</div>
