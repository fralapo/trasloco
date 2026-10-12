<?php
/** Read-only agency status page; never render usernames or passwords. */
?>
<div class="tr-page">
  <header class="tr-head">
    <h1><?php esc_html_e( 'Agency sites', TRASLOCO_PLUGIN_NAME ); ?></h1>
    <p class="tr-lead"><?php esc_html_e( 'Monitor sites connected with WordPress Application Passwords. Credentials stay in server configuration.', TRASLOCO_PLUGIN_NAME ); ?></p>
  </header>
  <section class="tr-card">
    <?php if ( empty( $sites ) ) : ?>
      <p><?php esc_html_e( 'No sites configured. See docs/AGENCY.md to connect WordPress installations.', TRASLOCO_PLUGIN_NAME ); ?></p>
    <?php else : ?>
      <div style="overflow-x:auto">
        <table class="widefat striped">
          <thead><tr>
            <th scope="col"><?php esc_html_e( 'Site', TRASLOCO_PLUGIN_NAME ); ?></th>
            <th scope="col"><?php esc_html_e( 'Connection', TRASLOCO_PLUGIN_NAME ); ?></th>
            <th scope="col"><?php esc_html_e( 'Backup storage', TRASLOCO_PLUGIN_NAME ); ?></th>
            <th scope="col"><?php esc_html_e( 'Active job', TRASLOCO_PLUGIN_NAME ); ?></th>
          </tr></thead>
          <tbody><?php foreach ( $sites as $site ) : ?>
          <tr>
            <th scope="row"><?php echo esc_html( $site['name'] ); ?></th>
            <td><?php echo esc_html( $site['state']['status'] ); ?></td>
            <td><?php echo esc_html( isset( $site['state']['storage'] ) ? ( $site['state']['storage'] ? 'OK' : 'No' ) : '-' ); ?></td>
            <td><?php echo esc_html( isset( $site['state']['job'] ) ? $site['state']['job'] : '-' ); ?></td>
          </tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
