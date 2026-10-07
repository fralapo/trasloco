<?php
/**
 * Who can download backup files. Shown on the Backups page and on the Guide page.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<aside class="tr-note" role="note">
	<span class="dashicons dashicons-lock" aria-hidden="true"></span>
	<p><?php echo wp_kses( __( '<strong>Who can download these files?</strong> Visitors cannot list the files in this folder, and the name of every new file ends with a random code that is hard to guess. Anyone who has the exact link can still download the file, and it contains the whole site, user accounts included. Do not share these links, and delete the backups you no longer need. Files made with Trasloco 1.0.0 end with a short number instead: download them and delete them from the server.', TRASLOCO_PLUGIN_NAME ), array( 'strong' => array() ) ); ?></p>
</aside>
