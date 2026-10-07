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

<ul id="trasloco-queries" class="tr-queries">
	<li class="trasloco-query">
		<div class="tr-query-fields">
			<label>
				<span><?php esc_html_e( 'Text to find', TRASLOCO_PLUGIN_NAME ); ?></span>
				<input class="trasloco-query-find-input" type="text" autocomplete="off" name="options[replace][old_value][]" />
			</label>
			<label>
				<span><?php esc_html_e( 'Replace with', TRASLOCO_PLUGIN_NAME ); ?></span>
				<input class="trasloco-query-replace-input" type="text" autocomplete="off" name="options[replace][new_value][]" />
			</label>
		</div>
	</li>
</ul>

<button type="button" class="tr-btn tr-btn-ghost" id="trasloco-add-new-replace-button">
	<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
	<?php esc_html_e( 'Add another replacement', TRASLOCO_PLUGIN_NAME ); ?>
</button>
