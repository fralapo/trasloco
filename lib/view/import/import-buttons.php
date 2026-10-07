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

<?php if ( is_readable( TRASLOCO_STORAGE_PATH ) && is_writable( TRASLOCO_STORAGE_PATH ) ) : ?>
	<div class="trasloco-import-messages" aria-live="polite"></div>

	<div class="trasloco-import-form">
		<div class="trasloco-drag-drop-area tr-drop" id="trasloco-drag-drop-area">
			<div id="trasloco-import-init" class="tr-drop-inner">
				<span class="dashicons dashicons-cloud-upload tr-drop-icon" aria-hidden="true"></span>
				<p class="tr-drop-title"><?php esc_html_e( 'Drop the .wpress file here', TRASLOCO_PLUGIN_NAME ); ?></p>
				<p class="tr-hint"><?php esc_html_e( 'or', TRASLOCO_PLUGIN_NAME ); ?></p>
				<label id="trasloco-import-file" class="tr-btn tr-btn-primary">
					<span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
					<?php esc_html_e( 'Choose a file from your computer', TRASLOCO_PLUGIN_NAME ); ?>
					<input type="file" id="trasloco-select-file" accept=".wpress" class="screen-reader-text" />
				</label>
				<p class="tr-hint tr-limit"><?php esc_html_e( 'No size limit. The file is uploaded in chunks: if the connection drops, the upload retries on its own.', TRASLOCO_PLUGIN_NAME ); ?></p>
			</div>
		</div>
	</div>
<?php else : ?>
	<div class="tr-note tr-note-error" role="alert">
		<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
		<p><?php printf( wp_kses( __( '<strong>Cannot write to the working folder.</strong> Make sure <code>%s</code> exists and is readable and writable.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array(), 'code' => array() ) ), esc_html( TRASLOCO_STORAGE_PATH ) ); ?></p>
	</div>
<?php endif; ?>
