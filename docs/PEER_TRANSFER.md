# Trasloco peer transfer

`wp trasloco pull-site` copies one verified `.wpress` archive from another Trasloco installation. Run the command on the destination WordPress site. The source site must have Trasloco active, pretty REST permalinks enabled, and a dedicated WordPress Application Password belonging to an administrator. Transfer endpoints require `manage_options` and never trigger an import or restore.

Set the source account in the destination process environment; do not put its password in shell history or the command arguments:

```sh
export TRASLOCO_PEER_USER='backup-transfer'
read -r -s TRASLOCO_PEER_APP_PASSWORD
export TRASLOCO_PEER_APP_PASSWORD
wp trasloco pull-site https://source.example.com backup-20261014.wpress
unset TRASLOCO_PEER_APP_PASSWORD TRASLOCO_PEER_USER
```

The source URL must use HTTPS with certificate verification. Redirects, embedded credentials, query strings, fragments and nonstandard ports are rejected. The downloader uses WordPress's safe HTTP transport to reject unsafe destinations. The source administrator can revoke the Application Password after use.

The destination saves incomplete bytes in a mode-0700 private directory outside the public WordPress tree, then resumes on a 512-KiB boundary. The default is the system temporary directory. If that directory and the configured backup directory are on different filesystems, configure `TRASLOCO_PRIVATE_TRANSFER_DIR` in `wp-config.php` to an existing writable directory outside the webroot on the same filesystem as `TRASLOCO_BACKUPS_DIR`. Publication uses no-overwrite hard links, verifies the full SHA-256, and writes a local integrity sidecar. If transfer stops, rerun the same command while the source archive remains unchanged. Private partial data is retained to allow resumption.

An existing identical archive is accepted idempotently, which allows recovery after a process stops between archive publication and manifest creation. An existing archive with different bytes or a conflicting manifest is never replaced. Successful transfer only makes the backup available locally; review and run a separate restore operation when appropriate.

The local integration suite covers administrator-only manifest/chunk endpoints, bounded aligned fragments, resumed downloads, digest verification, idempotent completion and refusal to replace different bytes. A live transfer across separate public hosts, hosting WAF behavior and provider-specific rate limits still require deployment testing.
