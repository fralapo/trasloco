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
		<p class="tr-lead"><?php esc_html_e( 'Pack the database, media, themes and plugins into a single file, then import it on the destination site.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>

	<form action="" method="post" id="trasloco-export-form" class="tr-form">

		<section class="tr-card">
			<h2><?php esc_html_e( 'What do you want to export?', TRASLOCO_PLUGIN_NAME ); ?></h2>
			<p class="tr-hint"><?php esc_html_e( 'Leave everything as it is to export the whole site. That is what you need most of the time.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<?php include TRASLOCO_TEMPLATES_PATH . '/export/advanced-settings.php'; ?>
		</section>

		<section class="tr-card">
			<h2><?php esc_html_e( 'Change the site address', TRASLOCO_PLUGIN_NAME ); ?> <span class="tr-badge"><?php esc_html_e( 'optional', TRASLOCO_PLUGIN_NAME ); ?></span></h2>
			<p class="tr-hint"><?php esc_html_e( 'If the new site will have a different address, enter the old and the new one here. It is replaced everywhere in the database, serialized data included.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<?php include TRASLOCO_TEMPLATES_PATH . '/export/find-replace.php'; ?>
		</section>

		<?php do_action( 'trasloco_export_left_options' ); ?>

		<div class="tr-actions">
			<a href="#" id="trasloco-export-file" class="tr-btn tr-btn-primary" role="button">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Create export file', TRASLOCO_PLUGIN_NAME ); ?>
			</a>
			<span class="tr-hint"><?php esc_html_e( 'You can download it when it is ready. Keep this tab open until it finishes.', TRASLOCO_PLUGIN_NAME ); ?></span>
		</div>

		<input type="hidden" name="trasloco_manual_export" value="1" />
	</form>

	<?php do_action( 'trasloco_export_left_end' ); ?>
</div>
