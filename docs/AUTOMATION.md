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

## Encrypted offsite replication

Use a supported [rclone](https://rclone.org/) remote with a **crypt** wrapper, then replicate the backup folder:

```bash
rclone copy /srv/private/wordpress-backups encrypted-remote:trasloco/my-site --checksum
```

Run this only after a successful backup, preferably from a single cron wrapper script that exits on errors. Never commit rclone credentials or plaintext database passwords into the repository.

## Recovery rehearsal

Each month, download a random backup from the offsite destination and restore it on an isolated staging WordPress installation. Confirm database, user accounts, media, forms, URLs, and themes. Also verify backup age and available storage.

## Still to implement
- A production-provider-tested WebDAV integration in backup and WP-CLI workflows. The current WebDAV stream adapter is loaded by the plugin and passes local contract tests, but no backup or command uses it yet.
- S3 and other cloud connectors, remote retry/resume and provider health checks.
- Incremental/differential backup chains and retention dependency management.
