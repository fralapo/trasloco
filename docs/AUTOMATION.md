# Automated backups and offsite storage

Trasloco includes a visual backup scheduler with hourly, daily, weekly and calendar-month frequencies, verified retention and an optional recovery point before WordPress updates. These scheduled jobs use Action Scheduler when available and otherwise rely on WP-Cron. WP-Cron needs site traffic to run on time; a system cron can invoke `wp cron event run --due-now` for low-traffic sites. Incremental archives and cloud connectors are still open.

## Daily WP-CLI backup

For a system-scheduled CLI backup, install WP-CLI on the hosting server, verify a manual export first, and edit paths and executable locations:

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
wp trasloco offsite queue-upload backup.wpress.enc
wp trasloco offsite queue-download backup.wpress.enc
wp trasloco offsite status
TRASLOCO_ARCHIVE_PASSPHRASE='use a secret from your secret manager' wp trasloco decrypt backup.wpress.enc
```

The encrypted archive carries a SHA-256 sidecar. Upload publishes the sidecar last, verifies both remote objects by reading them back and never replaces different remote content. Plaintext `.wpress` upload requires the explicit `--allow-unencrypted` option. Downloads use verified byte ranges and keep private, locked checkpoints in the backup directory; after a process interruption they resume from the last complete 1 MiB block. The provider must honor HTTP byte-range requests exactly. A complete archive is checked against its sidecar before local publication, and existing local files are never replaced. WebDAV upload divides the verified archive into immutable 8 MiB objects and publishes a small index only after all parts have passed read-back checks. Trasloco presents that representation as one archive for download, verification and inventory; use Trasloco to retrieve multipart-backed WebDAV archives rather than downloading the index as if it were a `.wpress` file. Upload and download retry temporary transport errors up to three times, and persisted WebDAV parts allow an interrupted upload to resume. Queue either direction with `queue-upload` or `queue-download`; check progress in the admin jobs panel, and retry an eligible failed job with `wp trasloco offsite retry-upload <job-id>` or `retry-download <job-id>`. Jobs use Action Scheduler when available or the WordPress cron fallback. Durable uploads currently require the resumable WebDAV adapter; the local mounted-directory driver does not offer resumable upload jobs. `wp trasloco offsite status` checks provider reachability and reports archive/manifest pairs that appear incomplete; it does not hash every remote archive. WebDAV has local protocol mocks but still needs testing against real providers. A mounted private directory can instead use `TRASLOCO_REMOTE_DRIVER=local` and `TRASLOCO_REMOTE_DIRECTORY` for synchronous transfers.

Never commit storage credentials or plaintext database passwords into the repository. Rehearse a download and isolated restore before relying on a provider for disaster recovery.

For a direct transfer between two WordPress sites, the destination can run [`wp trasloco pull-site`](PEER_TRANSFER.md). This path requires an administrator Application Password on the source and verifies the complete archive before it becomes available locally. It is a manual transfer; it does not restore the archive or replace cloud offsite storage.

## Recovery rehearsal

Each month, download a random backup from the offsite destination and restore it on an isolated staging WordPress installation. Confirm database, user accounts, media, forms, URLs, and themes. Also verify backup age and available storage.

## Still to implement
- S3 and other cloud connectors, automatic backup-to-remote scheduling, provider health checks and alerts, and live tests against real WebDAV services.
- Incremental/differential backup chains and retention dependency management.
