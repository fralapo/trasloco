<?php
/** Scheduled backup controls. All mutations require admin-post + nonce. */
?>
<div class="tr-page">
    <header class="tr-head">
        <h1><?php esc_html_e( 'Automatic backups', TRASLOCO_PLUGIN_NAME ); ?></h1>
        <p class="tr-lead"><?php esc_html_e( 'Run complete backups automatically without leaving the browser open.', TRASLOCO_PLUGIN_NAME ); ?></p>
    </header>
    <section class="tr-card">
        <h2><?php esc_html_e( 'Backup schedule', TRASLOCO_PLUGIN_NAME ); ?></h2>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action" value="trasloco_schedule_save">
            <?php wp_nonce_field( 'trasloco_schedule_save' ); ?>
            <p><label><input type="checkbox" name="schedule[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Enable automatic backups', TRASLOCO_PLUGIN_NAME ); ?></label></p>
            <p><label for="tr-interval"><?php esc_html_e( 'Frequency', TRASLOCO_PLUGIN_NAME ); ?></label>
                <select id="tr-interval" name="schedule[interval]">
                    <?php foreach ( array( 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly' ) as $frequency => $label ) : ?>
                        <option value="<?php echo esc_attr( $frequency ); ?>" <?php selected( $settings['interval'], $frequency ); ?>><?php echo esc_html( trasloco_translate( $label ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p><label for="tr-keep"><?php esc_html_e( 'Keep the newest backups', TRASLOCO_PLUGIN_NAME ); ?></label>
                <input id="tr-keep" type="number" name="schedule[keep]" min="1" max="365" value="<?php echo esc_attr( $settings['keep'] ); ?>" required>
            </p>
            <p><label><input type="checkbox" name="schedule[notify]" value="1" <?php checked( ! empty( $settings['notify'] ) ); ?>> <?php esc_html_e( 'Email the administrator when a backup fails', TRASLOCO_PLUGIN_NAME ); ?></label></p>
            <button class="tr-btn tr-btn-primary" type="submit"><?php esc_html_e( 'Save schedule', TRASLOCO_PLUGIN_NAME ); ?></button>
        </form>
        <p class="tr-hint"><?php esc_html_e( 'WP-Cron normally runs when the site receives visits. Configure server cron for reliable timing.', TRASLOCO_PLUGIN_NAME ); ?></p>
    </section>
    <section class="tr-card">
        <h2><?php esc_html_e( 'Backup activity', TRASLOCO_PLUGIN_NAME ); ?></h2>
        <?php if ( is_array( $active ) && ! empty( $active['state'] ) ) : ?>
            <p><?php echo esc_html( ucfirst( $active['state'] ) ); ?> · <?php echo esc_html( $active['id'] ); ?></p>
        <?php elseif ( is_array( $last ) && ! empty( $last['state'] ) ) : ?>
            <p><?php echo esc_html( ucfirst( $last['state'] ) ); ?><?php if ( ! empty( $last['archive'] ) ) : ?> · <?php echo esc_html( $last['archive'] ); ?><?php endif; ?></p>
            <?php if ( ! empty( $last['error'] ) ) : ?><p class="tr-hint"><?php echo esc_html( $last['error'] ); ?></p><?php endif; ?>
        <?php else : ?>
            <p><?php esc_html_e( 'No automatic backup has run yet.', TRASLOCO_PLUGIN_NAME ); ?></p>
        <?php endif; ?>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action" value="trasloco_schedule_start">
            <?php wp_nonce_field( 'trasloco_schedule_start' ); ?>
            <button class="tr-btn" type="submit" <?php disabled( ! empty( $active ) ); ?>><?php esc_html_e( 'Run backup now', TRASLOCO_PLUGIN_NAME ); ?></button>
        </form>
    </section>
</div>
