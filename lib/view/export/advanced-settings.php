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

<details class="tr-details">
	<summary><?php esc_html_e( 'Leave parts of the site out (advanced)', TRASLOCO_PLUGIN_NAME ); ?></summary>
	<p class="tr-hint"><?php esc_html_e( 'Tick an option only if you know you do not need that part on the new site. Each option says what is left out.', TRASLOCO_PLUGIN_NAME ); ?></p>
	<?php foreach ( trasloco_export_option_groups() as $tr_group ) : ?>
		<fieldset class="tr-opts">
			<legend><?php echo esc_html( $tr_group['title'] ); ?></legend>
			<?php foreach ( $tr_group['options'] as $tr_key => $tr_opt ) : ?>
				<?php $tr_id = 'trasloco-' . str_replace( '_', '-', $tr_key ); ?>
				<div class="tr-opt">
					<input type="checkbox" id="<?php echo esc_attr( $tr_id ); ?>" name="options[<?php echo esc_attr( $tr_key ); ?>]" aria-describedby="<?php echo esc_attr( $tr_id ); ?>-help" />
					<label for="<?php echo esc_attr( $tr_id ); ?>"><?php echo esc_html( $tr_opt['label'] ); ?></label>
					<p id="<?php echo esc_attr( $tr_id ); ?>-help" class="tr-hint"><?php echo esc_html( $tr_opt['help'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</fieldset>
	<?php endforeach; ?>
	<?php do_action( 'trasloco_export_advanced_settings' ); ?>
</details>
