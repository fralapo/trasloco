<?php
/** Read-only agency status page. It does not trigger remote work. */
?>
<div class="tr-page">
	<header class="tr-head">
		<h1><?php esc_html_e( 'Agency sites', TRASLOCO_PLUGIN_NAME ); ?></h1>
		<p class="tr-lead"><?php esc_html_e( 'A read-only overview of sites configured in wp-config.php. It never starts backups, restores, or remote commands.', TRASLOCO_PLUGIN_NAME ); ?></p>
	</header>
	<section class="tr-card" aria-labelledby="tr-agency-sites">
		<h2 id="tr-agency-sites"><?php esc_html_e( 'Configured sites', TRASLOCO_PLUGIN_NAME ); ?></h2>
		<?php if ( empty( $sites ) ) : ?>
			<p><?php esc_html_e( 'No sites are configured. See the Agency monitoring guide to add read-only connections.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php else : ?>
			<div class="tr-snapshot-table-wrap" role="region" tabindex="0" aria-label="<?php echo esc_attr__( 'Agency site status table', TRASLOCO_PLUGIN_NAME ); ?>">
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Site', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Connection', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Backups', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Schedule', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Active jobs', TRASLOCO_PLUGIN_NAME ); ?></th>
						<th scope="col"><?php esc_html_e( 'Failed jobs', TRASLOCO_PLUGIN_NAME ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $sites as $site ) : ?>
						<?php $health = $site['health']; ?>
						<tr>
							<th scope="row"><?php echo esc_html( $site['name'] ); ?></th>
							<td><?php
								$status_labels = array(
									'connected'    => __( 'Connected', TRASLOCO_PLUGIN_NAME ),
									'unreachable'  => __( 'Unreachable', TRASLOCO_PLUGIN_NAME ),
									'unauthorized' => __( 'Unauthorized', TRASLOCO_PLUGIN_NAME ),
									'invalid'      => __( 'Invalid response or configuration', TRASLOCO_PLUGIN_NAME ),
									'not_checked'  => __( 'Not checked yet', TRASLOCO_PLUGIN_NAME ),
								);
								echo esc_html( isset( $status_labels[ $health['status'] ] ) ? $status_labels[ $health['status'] ] : __( 'Unknown', TRASLOCO_PLUGIN_NAME ) );
							?></td>
							<td><?php echo isset( $health['backups'] ) ? (int) $health['backups'] : '—'; ?></td>
							<td><?php
								$schedule_labels = array(
									'off'     => __( 'Disabled', TRASLOCO_PLUGIN_NAME ),
									'hourly'  => __( 'Hourly', TRASLOCO_PLUGIN_NAME ),
									'daily'   => __( 'Daily', TRASLOCO_PLUGIN_NAME ),
									'weekly'  => __( 'Weekly', TRASLOCO_PLUGIN_NAME ),
									'monthly' => __( 'Monthly', TRASLOCO_PLUGIN_NAME ),
									'unknown' => __( 'Unknown', TRASLOCO_PLUGIN_NAME ),
								);
								echo esc_html( isset( $schedule_labels[ $health['schedule'] ] ) ? $schedule_labels[ $health['schedule'] ] : '—' );
							?></td>
							<td><?php echo isset( $health['active_jobs'] ) ? (int) $health['active_jobs'] : '—'; ?></td>
							<td><?php echo isset( $health['failed_jobs'] ) ? (int) $health['failed_jobs'] : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="tr-hint"><?php esc_html_e( 'Status is cached for five minutes. Credentials are not saved in WordPress options or shown in this page.', TRASLOCO_PLUGIN_NAME ); ?></p>
			<p class="tr-hint"><?php esc_html_e( 'The page spends at most ten seconds checking remote sites. Reload it to check sites that were not reached yet.', TRASLOCO_PLUGIN_NAME ); ?></p>
		<?php endif; ?>
	</section>
</div>
