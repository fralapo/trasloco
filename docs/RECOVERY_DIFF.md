# Offline Recovery File Comparison

After a failed WordPress restore, the recovery-point snapshot may have the original site database and files, but an interrupted import may have overwritten only some live files. Do not assume that returning the database restores those files.

From a trusted machine with PHP CLI and access to the original private recovery archive and site files:

```bash
php tools/recovery-status.php /private/trasloco-backups --json
php tools/recovery-diff.php /private/trasloco-backups/recovery-example.wpress /srv/site/wp-content --json
```

To omit a noisy path from the content comparison, add one or more exact relative paths. An excluded directory also excludes its descendants:

```bash
php tools/recovery-diff.php /private/recovery-example.wpress /srv/site/wp-content \
  --exclude=uploads/cache --exclude=plugins/example-plugin/generated --json
```

Exclusions must stay under `uploads/`, `themes/`, `plugins/` or `mu-plugins/`; absolute paths, traversal components and control characters are rejected. They skip content comparison only. Trasloco still validates the archive checksum, every archive header, entry length and path, and counts excluded entries in `files_excluded`. This option affects this read-only report only; it does not change an import or authorize file deletion.

The comparison is **read only** and **does not bootstrap WordPress or MySQL**. It validates the backup's versioned SHA-256 manifest, validates archive structure and paths, checks contents in fixed-size chunks, and reports counts plus at most 25 relative file names in states `identical`, `modified`, `missing`, or `unsafe_target`. A symlink or non-file target is never traversed. Archive work is limited to 100,000 content files and 2 GiB by default; larger sites require an explicitly reviewed implementation change.

Limitations: the default mode does not detect files absent from the archive; an explicit optional inventory identifies *untracked* files but cannot prove whether they came from the failed import or legitimate new site activity. It does not inspect database mutations or concurrent live writes. It **does not delete or replace anything** and is not an automated, atomic rollback. Do not use `rsync --delete` against production based only on this report. For a full rollback, stop writes and rehearse disaster recovery on an isolated site; follow the recovery runbook.

Only a backup with a valid local SHA-256 sidecar is eligible; legacy backups without sidecars are rejected. The manifest is a corruption check, not proof of who created the backup.

## Manual recovery of missing files

If the comparison reports missing/modified files after a database rollback, use the existing standalone rescue command to create a **new private staging directory**, then review the staged content and perform an operator-controlled restore of the affected files:

```bash
php tools/rescue-extract.php /private/trasloco-backups/recovery-example.wpress --content --output=/private/manual-file-rescue
```

The command does not write into the live site. Take the site offline before any manual copy, check permissions and unexpected files, and avoid deleting live files not present in the archive. Database recovery alone is not a full site rollback.


## Opt-in inventory of untracked destination files

```bash
php tools/recovery-diff.php /private/recovery-example.wpress /srv/site/wp-content --scan-untracked --json
```

This independently inventories the `uploads/`, `plugins/`, `themes/` and `mu-plugins/` directories without following symlinks. The report includes `not_in_snapshot`, `unsafe_untracked`, `destination_entries_scanned` and up to 25 `untracked_sample` entries, all with **relative paths only**. Scanning is optional because large production directories can be expensive. An additional limit of 200,000 destination entries fails closed rather than silently reporting a partial result. The archived-content safety bounds remain 100,000 files and 2 GiB.

**Important:** `not_in_snapshot` means only that the file is not represented in the recovery archive. It is **not** proof that the failed import created it; cache files, excluded plugin files and subsequent uploads may also appear. Do not automatically delete these files. Complete rollback still requires an operator-reviewed, isolated restore rehearsal and database consistency checks.

Destination filesystem names containing ASCII terminal control characters are escaped as printable `\\xHH` sequences in the untracked sample. Non-UTF-8 byte sequences are also escaped rather than breaking the JSON report. Those are display strings, **not safe copy/delete paths**.

## Read-only SQL operation inventory

To see the table operations encoded in a recovery archive without starting WordPress or connecting to MySQL:

```bash
php tools/recovery-sql-preview.php /private/trasloco-backups/recovery-example.wpress --json
```

The command requires a valid SHA-256 sidecar and scans at most 1 GiB of `database.sql` in bounded chunks. It reports the `DROP`, `CREATE`, `INSERT`, `REPLACE`, `ALTER` and `TRUNCATE` statements recognized in Trasloco-generated exports, grouped by table. It never executes SQL or emits row contents.

This is an operation inventory, **not a semantic database changeset**: it does not validate arbitrary SQL syntax, count affected rows, compare values, inspect the destination database or approve a restore. Treat the archive as authoritative input and rehearse a restore against an isolated copy before production use.

The administrator-only REST archive preview also compares recognized table names with the current site's table names when Trasloco can safely infer the single-site prefix mapping. It labels likely table creation, modification or replacement and returns `unknown_mapping` where multisite or source naming makes that inference unsafe. This still does not compare rows, validate arbitrary SQL, predict plugin behavior or make an import safe; it is only a table-level review aid.

## Restore selected database tables

On a single-site installation, WP-CLI can restore selected table suffixes from a local backup. The first command is read-only and prints the table mapping and whether each destination table will be replaced or created:

```bash
wp trasloco restore-tables site-backup.wpress --tables=posts,postmeta
```

Review the plan, pause site writes, and pass `--yes` to apply it:

```bash
wp trasloco restore-tables site-backup.wpress --tables=posts,postmeta --yes
```

The archive must have a valid SHA-256 sidecar and complete `DROP`/`CREATE` operations for each selected table. Before changing SQL, Trasloco creates and verifies a full-site recovery point; if that backup fails, it stops. The command leaves archived files and plugin/theme activation unchanged, and only executes recognized SQL statements whose source table is on the explicit allowlist. MySQL DDL is not transactional, so a failed operation may leave a partially replaced table; use the recovery receipt and rehearsed recovery procedure before retrying. Restoring related tables separately can produce inconsistent data. Multisite is unsupported, and the command does not pause concurrent application writes for you.
