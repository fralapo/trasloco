# Independent offline disaster recovery (PHP CLI)

This utility is intentionally independent of WordPress, WP-CLI and MySQL.
It can inspect and stage an existing backup even if the original website no
longer boots. It **never restores a running website or executes SQL**.

## Before starting

Copy `.wpress` and its `.wpress.manifest.json` sidecar (if available) to a
**private** filesystem outside the web document root. Check free disk space
for a full extracted copy. A SHA-256 manifest detects corruption but does not
prove the backup's origin or the safety of PHP code inside the backup.

## Inspect and extract

```bash
php tools/rescue-extract.php /private/backups/example.wpress \
  --content --output=/private/recovery-20261012
```

The `--output` path must be absolute, have a writable existing parent, and
**must not exist already**. The utility creates it with permissions `0700`
and recovered files with `0600`. Content is staged under `content/uploads`,
`content/plugins` and `content/themes` as applicable. Metadata is saved as
`database.sql` and `package.json`, plus `multisite.json` when present.

Omit `--content` to extract only metadata. Omit `--output` for a random
private directory under the operating system's temporary directory; the output
path is printed to the terminal. The staging operation is bounded to 1 GiB of
selected file content by default. Never use it for an in-place restore.

## How to rehearse recovery safely

1. Restore a separate test WordPress instance, not the original production
   database or content directory.
2. Independently check the extracted media files and the SQL backup before
   planning a restore. Do not run an untrusted SQL file against a live database.
3. Use the plugin's fully tested WordPress import flow on the isolated site
   whenever WordPress is available; its SQL format and URL remapping are more
   complex than executing `database.sql` directly.
4. Verify users, posts, attachments, permalinks and application behavior.
   Record the test date, target configuration and failures.

The offline CLI is a **data rescue** path, not proof of a bootable WordPress
restore. Work to support a fully independent isolated restore remains open.

## Negative integrity test

When a sidecar is present and does not match the archive, extraction is refused
**before** any output folder is created. The CI integration suite corrupts a
real backup without updating its manifest and checks this fail-closed behavior.
A manifest-free legacy archive may still be inspected, but the CLI reports it
as unverified; structural validation alone cannot prove data authenticity.
