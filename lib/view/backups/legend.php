<?php
/**
 * What each action on the Backups page does. Shown on the Backups page and on the Guide page.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<dl class="tr-legend">
	<div>
		<dt><?php esc_html_e( 'Download', TRASLOCO_PLUGIN_NAME ); ?></dt>
		<dd><?php esc_html_e( 'Saves the file on your computer. Keep a copy there too: if the server breaks, the files stored on it are lost with it.', TRASLOCO_PLUGIN_NAME ); ?></dd>
	</div>
	<div>
		<dt><?php esc_html_e( 'Restore', TRASLOCO_PLUGIN_NAME ); ?></dt>
		<dd><?php esc_html_e( 'Replaces this site with the content of the file, exactly like an import. Everything on the site now is overwritten.', TRASLOCO_PLUGIN_NAME ); ?></dd>
	</div>
	<div>
		<dt><?php esc_html_e( 'Delete', TRASLOCO_PLUGIN_NAME ); ?></dt>
		<dd><?php esc_html_e( 'Removes the file from the server for good and frees disk space. The site itself does not change.', TRASLOCO_PLUGIN_NAME ); ?></dd>
	</div>
</dl>
