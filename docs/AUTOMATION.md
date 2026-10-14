# Automated backups and offsite storage

Trasloco includes a visual backup scheduler with hourly, daily, weekly and calendar-month frequencies, verified retention, an optional recovery point before WordPress updates, optional local incremental snapshots, and optional encrypted offsite upload to WebDAV or S3-compatible storage. These scheduled jobs use Action Scheduler when available and otherwise rely on WP-Cron. WP-Cron needs site traffic to run on time; a system cron can invoke `wp cron event run --due-now` for low-traffic sites. Enable **Store scheduled backups as local incremental snapshots** to capture the verified archive into the private content-addressed store in resumable batches. Snapshot catalogs count toward scheduled retention, including after explicit compaction, and recovery-point catalogs are preserved. Original `.wpress` archives remain until an administrator explicitly compacts a verified snapshot with `wp trasloco incremental compact backup.wpress`. The Backups page displays snapshot catalogs and measured file-byte savings; `wp trasloco restore backup.wpress` reconstructs a missing archive and enters the standard restore workflow. Remote object delivery and real-provider certification remain open. See [the incremental storage workflow](INCREMENTAL_BACKUP_DESIGN.md).

To enable offsite delivery, configure WebDAV as described below or configure S3 as described in [S3 offsite storage](OFFSITE_S3.md). Make `TRASLOCO_ARCHIVE_PASSPHRASE` available to every WordPress worker (prefer the process environment; a private `wp-config.php` constant is also supported), then enable **Encrypt and upload each scheduled backup to remote storage** on the Backups page. S3 also requires its optional runtime and PHP 8.1 or later. Use a passphrase of at least 12 characters and keep a copy in a separate secret manager. The setting is off by default. Trasloco encrypts the verified local `.wpress` archive, writes a SHA-256 manifest, and queues an immutable resumable upload. The passphrase is read when the backup job runs; it is never persisted in the schedule option or job payload. Only the encrypted `.enc` archive is uploaded, and its temporary local copy is removed after the upload job completes. If the passphrase or resumable provider configuration is missing, the scheduled backup reports failure and does not fall back to plaintext upload. With this option enabled, Trasloco checks provider reachability and archive/manifest pairing once per day, emails the site administrator when health changes, and sends a transition alert when a queued transfer fails. WP-Cron timing still depends on site traffic.

For a manual backup, configure the same passphrase and PHP sodium, verify the archive, open **Trasloco → Backups**, and select **Encrypt**. Encryption and decryption are durable jobs that process at most four 1-MiB authenticated frames per worker request. Their secretstream state is sealed with the WordPress authentication salt in the private job checkpoint; the passphrase is never sent in the form or saved in the job payload. The page polls job progress, and workers resume from the last flushed checkpoint after interruption. Encryption publishes a separate `.wpress.enc` file and manifest only after the final authenticated frame; decryption publishes a `-decrypted.wpress` file only after authentication and archive inspection. The resulting plaintext remains in the private backup directory, so download it and secure or remove it afterward. Final archive inspection and checksum-manifest generation still scan the completed file in a single worker request, so exceptionally large files can remain subject to host limits. Use `wp trasloco decrypt` or the standalone [`rescue-decrypt.php`](OFFLINE_RECOVERY.md) tool for independent recovery.

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

The WP-CLI offsite command supports a private mounted directory, HTTPS WebDAV and optional S3-compatible storage. Configure the provider in `wp-config.php`; keep the WebDAV directory and credentials out of the webroot and source control:

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

The encrypted archive carries a SHA-256 sidecar. Upload publishes the sidecar last, verifies both remote objects by reading them back and never replaces different remote content. Plaintext `.wpress` upload requires the explicit `--allow-unencrypted` option. Downloads use verified byte ranges and keep private, locked checkpoints in the backup directory; after a process interruption they resume from the last complete 1 MiB block. The provider must honor HTTP byte-range requests exactly. A complete archive is checked against its sidecar before local publication, and existing local files are never replaced. WebDAV upload divides the verified archive into immutable 8 MiB objects and publishes a small index only after all parts have passed read-back checks. S3 multipart upload checkpoints its upload ID in the private job record and recovers saved parts with `ListParts`. Trasloco presents WebDAV's multipart representation as one archive for download, verification and inventory; use Trasloco to retrieve multipart-backed WebDAV archives rather than downloading the index as if it were a `.wpress` file. Upload and download retry temporary transport errors up to three times. Queue either direction with `queue-upload` or `queue-download`; check progress in the admin jobs panel, and retry an eligible failed job with `wp trasloco offsite retry-upload <job-id>` or `retry-download <job-id>`. Jobs use Action Scheduler when available or the WordPress cron fallback. Durable uploads require the resumable WebDAV or S3 adapter; the local mounted-directory driver supports synchronous upload jobs only. `wp trasloco offsite status` checks provider reachability and reports archive/manifest pairs that appear incomplete; it does not hash every remote archive. Local fixtures cover both remote adapters but real-provider tests remain open. A mounted private directory can instead use `TRASLOCO_REMOTE_DRIVER=local` and `TRASLOCO_REMOTE_DIRECTORY` for synchronous transfers.

Never commit storage credentials or plaintext database passwords into the repository. Rehearse a download and isolated restore before relying on a provider for disaster recovery.

For a direct transfer between two WordPress sites, the destination can run [`wp trasloco pull-site`](PEER_TRANSFER.md). This path requires an administrator Application Password on the source and verifies the complete archive before it becomes available locally. It is a manual transfer; it does not restore the archive or replace cloud offsite storage.

## Recovery rehearsal

Each month, download a random backup from the offsite destination and restore it on an isolated staging WordPress installation. Confirm database, user accounts, media, forms, URLs, and themes. Also verify backup age and available storage.

## Still to implement
- Other cloud connectors, live tests against real WebDAV/S3 services and provider-side durability tests.
- Snapshot-only restore, replacement of full `.wpress` files after verified capture, and remote content-addressed object delivery ([design and remaining gates](INCREMENTAL_BACKUP_DESIGN.md)).
