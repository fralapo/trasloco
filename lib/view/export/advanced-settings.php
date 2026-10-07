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

<?php
$tr_options = array(
	'no_spam_comments'    => __( 'Spam comments', TRASLOCO_PLUGIN_NAME ),
	'no_post_revisions'   => __( 'Post revisions', TRASLOCO_PLUGIN_NAME ),
	'no_media'            => __( 'Media library (images and files)', TRASLOCO_PLUGIN_NAME ),
	'no_themes'           => __( 'Themes', TRASLOCO_PLUGIN_NAME ),
	'no_inactive_themes'  => __( 'Inactive themes', TRASLOCO_PLUGIN_NAME ),
	'no_plugins'          => __( 'Plugins', TRASLOCO_PLUGIN_NAME ),
	'no_inactive_plugins' => __( 'Inactive plugins', TRASLOCO_PLUGIN_NAME ),
	'no_muplugins'        => __( 'Must-use plugins', TRASLOCO_PLUGIN_NAME ),
	'no_cache'            => __( 'Cache', TRASLOCO_PLUGIN_NAME ),
	'no_database'         => __( 'Database', TRASLOCO_PLUGIN_NAME ),
	'no_email_replace'    => __( 'Domain replacement in email addresses (database)', TRASLOCO_PLUGIN_NAME ),
);
?>
<details class="tr-details">
	<summary><?php esc_html_e( 'Choose what to exclude', TRASLOCO_PLUGIN_NAME ); ?></summary>
	<fieldset class="tr-checks">
		<legend class="screen-reader-text"><?php esc_html_e( 'Items to exclude from the export', TRASLOCO_PLUGIN_NAME ); ?></legend>
		<?php foreach ( $tr_options as $tr_key => $tr_label ) : ?>
			<label for="trasloco-<?php echo esc_attr( str_replace( '_', '-', $tr_key ) ); ?>">
				<input type="checkbox" id="trasloco-<?php echo esc_attr( str_replace( '_', '-', $tr_key ) ); ?>" name="options[<?php echo esc_attr( $tr_key ); ?>]" />
				<span><?php printf( esc_html__( 'Exclude: %s', TRASLOCO_PLUGIN_NAME ), esc_html( $tr_label ) ); ?></span>
			</label>
		<?php endforeach; ?>
		<?php do_action( 'trasloco_export_advanced_settings' ); ?>
	</fieldset>
</details>
