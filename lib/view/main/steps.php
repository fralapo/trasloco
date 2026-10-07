<?php
/**
 * The three stops of a site move, shown at the top of the Export and Import pages.
 * Set $tr_step (1, 2 or 3) before including this file.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$tr_steps = array(
	1 => array( __( 'Export', TRASLOCO_PLUGIN_NAME ), __( 'on the old site', TRASLOCO_PLUGIN_NAME ) ),
	2 => array( __( 'Import', TRASLOCO_PLUGIN_NAME ), __( 'on the new site', TRASLOCO_PLUGIN_NAME ) ),
	3 => array( __( 'Check', TRASLOCO_PLUGIN_NAME ), __( 'the new site', TRASLOCO_PLUGIN_NAME ) ),
);
?>
<div class="tr-route">
	<ol aria-label="<?php esc_attr_e( 'How a site move works', TRASLOCO_PLUGIN_NAME ); ?>">
		<?php foreach ( $tr_steps as $tr_n => $tr_s ) : ?>
			<li class="<?php echo $tr_n === $tr_step ? 'tr-route-here' : ''; ?>"<?php echo $tr_n === $tr_step ? ' aria-current="step"' : ''; ?>>
				<span class="tr-route-dot" aria-hidden="true"><?php echo (int) $tr_n; ?></span>
				<span class="tr-route-text"><strong><?php echo esc_html( $tr_s[0] ); ?></strong> <?php echo esc_html( $tr_s[1] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<a class="tr-route-help" href="<?php echo esc_url( network_admin_url( 'admin.php?page=trasloco_guide' ) ); ?>">
		<span class="dashicons dashicons-editor-help" aria-hidden="true"></span><?php esc_html_e( 'Read the guide', TRASLOCO_PLUGIN_NAME ); ?>
	</a>
</div>
