<?php
/**
 * Verified pre-restore recovery snapshot.
 * A failed snapshot MUST abort the destructive restore. The generated archive
 * lives in the normal backup directory, with SHA-256 manifest.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
class Trasloco_Recovery {
    /**
     * Complete a destination-site full backup before a command-line restore.
     * Do not call Trasloco_Export_Clean: it unconditionally exits.
     *
     * @return string the newly created, verified .wpress archive filename.
     */
    public static function capture() {
        if ( is_multisite() ) {
            throw new RuntimeException( 'Recovery snapshot does not yet support multisite.' );
        }
        if ( get_option( Trasloco_Background::ACTIVE, false ) ) {
            throw new RuntimeException( 'A scheduled backup is running: refuse concurrent restore.' );
        }
        if ( ! is_dir( TRASLOCO_BACKUPS_PATH ) || ! is_writable( TRASLOCO_BACKUPS_PATH ) ) {
            throw new RuntimeException( 'Backup storage is not writable; restore refused.' );
        }
        $available = @disk_free_space( TRASLOCO_BACKUPS_PATH );
        // Require at least 32 MiB before starting; archive size is not known yet.
        if ( $available !== false && $available < 32 * 1024 * 1024 ) {
            throw new RuntimeException( 'Insufficient space for a verified recovery point.' );
        }
        $params = array( 'options' => array( 'no_cache' => true ) );
        $success = false;
        $steps = Trasloco_Background::stages();
        add_filter( 'trasloco_completed_timeout', '__return_zero' );
        try {
            foreach ( $steps as $step ) {
                // Legacy export phases return 'completed'=>false on timeout.
                // Disabling timeouts via the filter makes them run to completion.
                for ( $iteration = 0; $iteration < 100000; $iteration++ ) {
                    $params = call_user_func( array( $step, 'execute' ), $params );
                    if ( ! is_array( $params ) ) {
                        throw new RuntimeException( 'Recovery step did not return a checkpoint.' );
                    }
                    if ( empty( $params['completed'] ) && isset( $params['completed'] ) ) {
                        unset( $params['completed'] );
                        continue;
                    }
                    unset( $params['completed'] );
                    break;
                }
                if ( $iteration === 100000 ) {
                    throw new RuntimeException( 'Recovery snapshot exceeded safe iteration limit.' );
                }
            }
            if ( empty( $params['archive'] ) ) {
                throw new RuntimeException( 'Recovery snapshot did not produce a backup.' );
            }
            $check = Trasloco_Manifest::inspect( $params['archive'], true );
            if ( $check['status'] !== 'verified' ) {
                throw new RuntimeException( 'Recovery snapshot checksum verification failed.' );
            }
            $success = true;
            return $params['archive'];
        } finally {
            remove_filter( 'trasloco_completed_timeout', '__return_zero' );
            if ( ! empty( $params['storage'] ) ) {
                try {
                    Trasloco_Directory::delete( trasloco_storage_path( $params ) );
                } catch ( Exception $ignored ) {
                    // The next cleanup sweep handles orphaned working files.
                }
            }
            if ( ! $success && ! empty( $params['archive'] ) ) {
                // An interrupted archive must never look like a viable
                // recovery point. Do not delete other previously verified backups.
                $result = Trasloco_Manifest::inspect( $params['archive'] );
                if ( $result['status'] !== 'verified' ) {
                    @unlink( trasloco_backup_path( $params ) );
                    @unlink( Trasloco_Manifest::manifest_path( $params['archive'] ) );
                }
            }
        }
    }
}
