<?php
/**
 * The stages of an import, shown on the Import page and on the Guide page.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<ol class="tr-steps">
	<li><?php echo wp_kses( __( '<strong>Upload.</strong> The file is sent to this site in small pieces. If a piece fails, it is sent again.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
	<li><?php echo wp_kses( __( '<strong>Check.</strong> Trasloco makes sure the file is a complete .wpress export.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
	<li><?php echo wp_kses( __( '<strong>Confirmation.</strong> You are asked to confirm. Nothing has been replaced yet: you can still stop here.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
	<li><?php echo wp_kses( __( '<strong>Files.</strong> Media, themes and plugins from the file are copied into the wp-content folder of this site.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
	<li><?php echo wp_kses( __( '<strong>Database.</strong> Content and settings are restored. The old address of the site is changed to the address of this site everywhere, and so are email addresses on the old domain (unless this was turned off on export).', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
	<li><?php echo wp_kses( __( '<strong>Finish.</strong> The theme and the plugins that were active on the old site are switched on. Some plugins are switched off so that you can log in at /wp-login.php: plugins that hide or rename the login page, reCAPTCHA, plugins that force https:// when this site has no security certificate, and the Jetpack image CDN and single sign-on. You can switch them on again on the Plugins page.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></li>
</ol>
