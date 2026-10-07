<?php
/**
 * Guide page: explains every part of Trasloco in plain words.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$tr_kses = array( 'strong' => array(), 'code' => array(), 'em' => array() );
$tr_toc  = array(
	'tr-g-what'     => __( 'What Trasloco does', TRASLOCO_PLUGIN_NAME ),
	'tr-g-words'    => __( 'Words used in this guide', TRASLOCO_PLUGIN_NAME ),
	'tr-g-move'     => __( 'Move a site, step by step', TRASLOCO_PLUGIN_NAME ),
	'tr-g-export'   => __( 'The Export page', TRASLOCO_PLUGIN_NAME ),
	'tr-g-import'   => __( 'The Import page', TRASLOCO_PLUGIN_NAME ),
	'tr-g-backups'  => __( 'The Backups page', TRASLOCO_PLUGIN_NAME ),
	'tr-g-problems' => __( 'If something goes wrong', TRASLOCO_PLUGIN_NAME ),
	'tr-g-cli'      => __( 'Command line (WP-CLI)', TRASLOCO_PLUGIN_NAME ),
);
?>
<div class="tr-page tr-guide">
	<header class="tr-head">
		<h1><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php esc_html_e( 'Guide', TRASLOCO_PLUGIN_NAME ); ?></h1>
		<p class="tr-lead"><?php esc_html_e( 'Everything Trasloco does, explained in plain words. You do not need to know how WordPress works inside.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>

	<nav class="tr-toc" aria-labelledby="tr-g-toc">
		<h2 id="tr-g-toc"><?php esc_html_e( 'On this page', TRASLOCO_PLUGIN_NAME ); ?></h2>
		<ul>
			<?php foreach ( $tr_toc as $tr_id => $tr_title ) : ?>
				<li><a href="#<?php echo esc_attr( $tr_id ); ?>"><?php echo esc_html( $tr_title ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<section id="tr-g-what" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-what'] ); ?></h2>
		<p><?php esc_html_e( 'Trasloco copies a whole WordPress site into one file, and puts that file back on another WordPress site. You can use it to:', TRASLOCO_PLUGIN_NAME ); ?></p>
		<ul class="tr-list">
			<li><?php esc_html_e( 'move a site to a new hosting company or to a new address;', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'make a test copy of a live site, to try changes without risk;', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'keep a backup that you can restore if something breaks.', TRASLOCO_PLUGIN_NAME ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Trasloco is free and open source. There are no paid add-ons and no account to create, and on 64-bit PHP there is no size limit.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</section>

	<section id="tr-g-words" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-words'] ); ?></h2>
		<dl class="tr-glossary">
			<dt><?php esc_html_e( 'Site address', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'The address people type to reach the site, such as https://www.example.com. WordPress saves it in its settings and inside the content, for example in every link and image.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Database', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Where WordPress keeps the text of posts and pages, comments, users, menus and every setting. It is not a file you can see in the site folders.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Media library', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php echo wp_kses( __( 'The images, videos, PDFs and other files uploaded to the site. They are stored in the <code>wp-content/uploads</code> folder.', TRASLOCO_PLUGIN_NAME ), $tr_kses ); ?></dd>
			<dt><?php esc_html_e( 'Theme', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Decides how the site looks. Only one theme is active at a time. A child theme is a theme that builds on another one, called the parent theme.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Plugin', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Adds a feature to the site, such as a contact form or a shop. A plugin is active when it is switched on, inactive when it is installed but switched off.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Must-use plugin', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php echo wp_kses( __( 'A plugin in the <code>wp-content/mu-plugins</code> folder. It is always on and does not appear in the normal list of plugins.', TRASLOCO_PLUGIN_NAME ), $tr_kses ); ?></dd>
			<dt><?php esc_html_e( '.wpress file', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'The single file that Trasloco creates. It holds the database and the files of the site. Only Trasloco can open it: do not try to unzip it.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Backup', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'A copy of the site that you can put back later. In Trasloco, a backup and an export file are the same thing.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Revision', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'An older version of a post or page. WordPress keeps one each time you save, so that you can go back to it.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Cache', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Ready-made copies of pages that some plugins save to make the site faster. They are rebuilt by themselves.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Hosting company', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'The company whose server runs the site. Its control panel shows things like disk space and the PHP version.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Domain', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'The name part of the site address, such as example.com. Email addresses use it too, as in info@example.com.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'HTTPS and security certificate', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'An address that starts with https:// is encrypted. For that the server needs a security certificate (SSL). A new hosting may need a few hours to set one up.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'PHP', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'The software WordPress runs on. The hosting company chooses its version; you can usually change it in the control panel.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'wp-config.php', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'A file in the main folder of WordPress with the name and password of its database. Every site keeps its own: Trasloco never copies it.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Permalinks', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'The way page addresses are built, such as /about-us/. After a move WordPress must rebuild them: that is why you save the permalink settings after an import.', TRASLOCO_PLUGIN_NAME ); ?></dd>
		</dl>
	</section>

	<section id="tr-g-move" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-move'] ); ?></h2>
		<ol class="tr-steps">
			<li><?php esc_html_e( 'On the new hosting, install WordPress, then install and activate Trasloco. The new site can be completely empty.', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'On the old site, open Trasloco → Export and click Create export file. When it is ready, click Download and save the .wpress file on your computer.', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'On the new site, open Trasloco → Import and drop the file on the page, or click Choose a file from your computer. When you are asked, click Replace site.', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'When the import is finished, log in with the username and password of the old site.', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'Open Settings → Permalinks and click Save Changes twice.', TRASLOCO_PLUGIN_NAME ); ?></li>
			<li><?php esc_html_e( 'Check the new site: a few pages, the images, the forms, and the shop if there is one.', TRASLOCO_PLUGIN_NAME ); ?></li>
		</ol>
		<p class="tr-hint"><?php esc_html_e( 'You do not need to change the site address by hand: the import does it for you.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</section>

	<section id="tr-g-export" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-export'] ); ?></h2>
		<p><?php esc_html_e( 'Create export file makes one .wpress file with the whole site. It is saved on this server, in Trasloco → Backups, and at the end you can download it. A large site can take several minutes: keep the tab open until the end.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<p><?php esc_html_e( 'Never in the file: WordPress itself, Trasloco, and the wp-config.php file (where WordPress keeps the password of its database). So the new site needs its own WordPress with Trasloco installed, and it keeps its own database password.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<p><?php esc_html_e( 'Also in the file: everything else in the wp-content folder, for example the backups made by other backup plugins. They can make the file much larger: delete the old ones there first if you do not need them.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<h3><?php esc_html_e( 'Leave parts of the site out (advanced)', TRASLOCO_PLUGIN_NAME ); ?></h3>
		<p><?php esc_html_e( 'All options are off by default, so the whole site is exported. This is what each option does when you tick it:', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php foreach ( trasloco_export_option_groups() as $tr_group ) : ?>
			<h4><?php echo esc_html( $tr_group['title'] ); ?></h4>
			<dl class="tr-glossary">
				<?php foreach ( $tr_group['options'] as $tr_opt ) : ?>
					<dt><?php echo esc_html( $tr_opt['label'] ); ?></dt>
					<dd><?php echo esc_html( $tr_opt['help'] ); ?></dd>
				<?php endforeach; ?>
			</dl>
		<?php endforeach; ?>
		<h3><?php esc_html_e( 'Find and replace text', TRASLOCO_PLUGIN_NAME ); ?></h3>
		<p><?php esc_html_e( 'You do not need this to move the site. When the file is imported, Trasloco changes the old address of the site to the new one by itself: in links, images and settings.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<p><?php esc_html_e( 'Use it for other text that must change on the new site, such as an old phone number or a second domain. Every exact match in the database is replaced when the file is imported, also inside settings saved by plugins.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<p><?php esc_html_e( 'Capital letters count: "Shop" and "shop" are different texts. Add one row for each text, with Add another replacement.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</section>

	<section id="tr-g-import" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-import'] ); ?></h2>
		<p><?php esc_html_e( 'The import replaces this site with the content of a .wpress file. Back up this site first, because there is no undo. These are the stages, in order:', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php include TRASLOCO_TEMPLATES_PATH . '/import/steps-list.php'; ?>
		<p><?php esc_html_e( 'The users of this site are replaced by the users of the old site, so you are logged out at the end. Log in with the username and password of the old site.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</section>

	<section id="tr-g-backups" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-backups'] ); ?></h2>
		<p><?php echo wp_kses( sprintf( __( 'Every export is saved on this server, in the %s folder. Here you can download, restore or delete each file.', TRASLOCO_PLUGIN_NAME ), '<code>' . esc_html( basename( WP_CONTENT_DIR ) . '/' . basename( TRASLOCO_BACKUPS_PATH ) ) . '</code>' ), array( 'code' => array() ) ); ?></p>
		<?php include TRASLOCO_TEMPLATES_PATH . '/backups/legend.php'; ?>
		<?php include TRASLOCO_TEMPLATES_PATH . '/backups/safety.php'; ?>
	</section>

	<section id="tr-g-problems" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-problems'] ); ?></h2>
		<dl class="tr-glossary">
			<dt><?php esc_html_e( 'The export or the import stops with an error', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Refresh the page and start again. If it stops again at the same point, ask your hosting company whether a firewall or a time limit blocks requests to wp-admin/admin-ajax.php.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'Pages show “Page not found” after the import', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Open Settings → Permalinks and click Save Changes twice.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'You cannot log in after the import', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Use the username and password of the old site. If you do not remember them, click “Lost your password?” on the login page: the email address is also the one of the old site.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'The file is not accepted', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Only .wpress files created by an export work. If the file is damaged, for example because the download was interrupted, export the old site again.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'A message says that the server is out of disk space', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Delete the backups you no longer need in Trasloco → Backups, or ask your hosting company for more space. An import needs free space about twice the size of the file.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'A message says that a folder cannot be written', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Trasloco saves files in wp-content/trasloco-backups and in its own storage folder. Ask your hosting company to make these folders writable by the web server.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'A message says that PHP is 32-bit', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( '32-bit PHP cannot handle files larger than 2 GB. Ask your hosting company to switch the site to 64-bit PHP.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><?php esc_html_e( 'The site is a WordPress Multisite network', TRASLOCO_PLUGIN_NAME ); ?></dt>
			<dd><?php esc_html_e( 'Networks of sites are not supported. Trasloco works with single WordPress sites.', TRASLOCO_PLUGIN_NAME ); ?></dd>
		</dl>
	</section>

	<section id="tr-g-cli" class="tr-card">
		<h2><?php echo esc_html( $tr_toc['tr-g-cli'] ); ?></h2>
		<p><?php esc_html_e( 'WP-CLI is a tool to manage WordPress by typing commands on the server. If your hosting has it, you can use Trasloco without opening this page:', TRASLOCO_PLUGIN_NAME ); ?></p>
		<dl class="tr-glossary">
			<dt><code>wp trasloco backup</code></dt>
			<dd><?php esc_html_e( 'Creates a backup in the backups folder, like Create export file. You can add the same options as the Export page, such as --exclude-media or --exclude-inactive-plugins, and --replace "old text" "new text".', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><code>wp trasloco backup --list</code></dt>
			<dd><?php esc_html_e( 'Lists the backups with their date and size.', TRASLOCO_PLUGIN_NAME ); ?></dd>
			<dt><code>wp trasloco restore &lt;file&gt;</code></dt>
			<dd><?php esc_html_e( 'Restores a backup from the backups folder. Write the file name as it appears on the Backups page.', TRASLOCO_PLUGIN_NAME ); ?></dd>
		</dl>
	</section>
</div>
