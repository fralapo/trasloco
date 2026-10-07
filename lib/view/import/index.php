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
		<p class="tr-lead"><?php esc_html_e( 'Upload a .wpress file created by an export. The current site (database, media, themes and plugins) will be replaced.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>

	<form action="" method="post" id="trasloco-import-form" class="tr-form" enctype="multipart/form-data">

		<?php do_action( 'trasloco_import_left_options' ); ?>

		<?php include TRASLOCO_TEMPLATES_PATH . '/import/import-buttons.php'; ?>

		<input type="hidden" name="trasloco_manual_import" value="1" />
	</form>

	<?php do_action( 'trasloco_import_left_end' ); ?>

	<aside class="tr-note" role="note">
		<span class="dashicons dashicons-warning" aria-hidden="true"></span>
		<p><?php echo wp_kses( __( '<strong>Before importing</strong>, back up the current site: the import overwrites everything and cannot be undone. Afterwards you will log in with the username and password of the <em>source</em> site.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array(), 'em' => array() ) ); ?></p>
	</aside>
</div>
