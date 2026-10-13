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

## Bootable restore into a freshly provisioned WordPress installation

A second, **different** recovery path now has an end-to-end CI rehearsal:
[`tests/disaster-rehearsal.sh`](../tests/disaster-rehearsal.sh). It bootstraps
a completely separate WordPress installation and empty MySQL/MariaDB database,
installs Trasloco, verifies the original `.wpress` bytes and restores a real
site archive. It checks migrated users, posts, uploaded media, the new site's
URLs, WordPress installation status and database integrity. The original
WordPress runtime is not used by the restore. The new environment **does**
require WordPress, WP-CLI and an operational database.

Operator procedure (rehearse before production):

1. Preserve an immutable/private copy of the source `.wpress` and its
   `.wpress.manifest.json` companion. Validate integrity before continuing.
2. Provision a **separate** empty database and new WordPress site on a
   non-public staging host, with appropriate PHP and database resources.
   Do not point the staging WordPress config at a live production database.
3. Install/activate the compatible Trasloco plugin on this new site.
   Configure a non-public backups directory where possible.
4. Copy the archive and any matching sidecar into that site's Trasloco
   backups directory, then run:

   ```bash
   wp trasloco verify site.wpress --path=/path/to/new/wordpress
   wp trasloco restore site.wpress --path=/path/to/new/wordpress
   wp core is-installed --path=/path/to/new/wordpress
   wp db check --path=/path/to/new/wordpress
   ```

   A legacy archive with no manifest cannot pass `verify` and must be
   treated as unverified. Inspect it separately before contemplating restore.
5. Compare the destination's actual `home` and `siteurl`, users,
   representative posts, media, login, scheduled jobs and application behavior.
   In a staging rehearsal, do not send real mail or process real orders.
6. Promote to production only through an operator-reviewed deployment plan
   with independent database and content backups, a maintenance window and
   a rollback process proven on the target hosting environment.

**Limits:** CI runs on MySQL 8.0 and MariaDB 11.4 with PHP 7.4/8.2/8.4.
It does not prove Nginx/Apache anonymous HTTP access controls, WooCommerce
order consistency, Multisite or automated rollback after a partial SQL import.
A restored database cannot be treated as an atomic transaction with filesystem
changes. The private offline extractor remains available when WordPress cannot
bootstrap at all.

## Find the pre-import recovery point after a SQL failure

Starting with this version, **before any destructive import**, Trasloco writes an independent, private filesystem receipt called `.trasloco-recovery-<id>.json` alongside the verified recovery archive. An import that encounters an SQL error or process crash leaves the receipt in `pending` state. A completed import changes it to `completed`. These receipts are **not** included in `.wpress` archives and must remain outside direct HTTP access.

Even when WordPress and MySQL are offline, an operator can locate the relevant backup with system PHP alone:

```bash
php tools/recovery-status.php /absolute/private/trasloco-backups --json
```

Only filenames and timestamps are displayed; no credentials or data are extracted. Keep the source backup and the `recovery-*.wpress` backup (with its manifest) unchanged. On a newly provisioned staging site, first verify that exact recovery archive. If the damaged target's WordPress can still boot, the operator can restore it explicitly with `wp trasloco restore recovery-....wpress`. The original `pending` receipt remains as a forensic record even after a separate manual recovery succeeds.

**Important:** restoring an earlier database does not necessarily remove additional files created during a failed migration. Review and reconcile files separately. This is not atomic full-site rollback, and no automatic rollback is attempted.
