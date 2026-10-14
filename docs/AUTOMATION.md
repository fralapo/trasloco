# Automated backups and offsite storage

Trasloco offers a WP-CLI backup command and optional retention. The plugin itself **does not yet provide a visual backup scheduler, incremental archives, or built-in cloud connectors**. Use a system scheduler and rclone for now.

## Daily WP-CLI backup

Install WP-CLI on the hosting server, verify a manual export first, and use cron (edit paths and executable locations):

```cron
0 3 * * * cd /var/www/wordpress && /usr/local/bin/wp trasloco backup --keep=7 --quiet
```

- `--keep=7` removes all but seven newest local `.wpress` backups only **after successful backup creation**.
- Without `--keep`, backups are never deleted automatically.
- Retention is not a substitute for independent offsite backups.
- Use the correct system user, restricted file permissions and a maintenance window on busy sites.
- Configure `TRASLOCO_BACKUPS_DIR` outside the public document root where possible.

## Encrypted offsite storage

The WP-CLI offsite command supports a private mounted directory and HTTPS WebDAV. Configure the provider in `wp-config.php`; keep the WebDAV directory and credentials out of the webroot and source control:

```php
define( 'TRASLOCO_REMOTE_DRIVER', 'webdav' );
define( 'TRASLOCO_WEBDAV_URL', 'https://webdav.example.net/backups/' );
define( 'TRASLOCO_WEBDAV_USER', getenv( 'TRASLOCO_WEBDAV_USER' ) );
define( 'TRASLOCO_WEBDAV_PASSWORD', getenv( 'TRASLOCO_WEBDAV_PASSWORD' ) );
```

Create and transfer an authenticated encrypted archive:

```bash
TRASLOCO_ARCHIVE_PASSPHRASE='use a secret from your secret manager' wp trasloco encrypt backup.wpress
wp trasloco offsite upload backup.wpress.enc
wp trasloco offsite download backup.wpress.enc
wp trasloco offsite status
TRASLOCO_ARCHIVE_PASSPHRASE='use a secret from your secret manager' wp trasloco decrypt backup.wpress.enc
```

The encrypted archive carries a SHA-256 sidecar. Upload publishes the sidecar last, verifies both remote objects by reading them back and never replaces different remote content. Plaintext `.wpress` upload requires the explicit `--allow-unencrypted` option. Downloads are staged privately, checked against the sidecar, then published without replacing existing local files. `wp trasloco offsite status` checks provider reachability and reports archive/manifest pairs that appear incomplete. It is an inventory check only: it does not read and hash every remote archive, so run an actual download and integrity verification for restore rehearsals. These transfers are manual WP-CLI operations; there is no automatic schedule, remote retry queue or provider health alert yet. A WebDAV adapter is covered by local protocol mocks but still needs testing against real WebDAV providers. A mounted private directory can instead use `TRASLOCO_REMOTE_DRIVER=local` and `TRASLOCO_REMOTE_DIRECTORY`.

Never commit storage credentials or plaintext database passwords into the repository. Rehearse a download and isolated restore before relying on a provider for disaster recovery.

## Recovery rehearsal

Each month, download a random backup from the offsite destination and restore it on an isolated staging WordPress installation. Confirm database, user accounts, media, forms, URLs, and themes. Also verify backup age and available storage.

## Still to implement
- S3 and other cloud connectors, remote retry/resume and provider health checks.
- Incremental/differential backup chains and retention dependency management.
