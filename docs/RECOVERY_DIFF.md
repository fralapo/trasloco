# Offline Recovery File Comparison

After a failed WordPress restore, the recovery-point snapshot may have the original site database and files, but an interrupted import may have overwritten only some live files. Do not assume that returning the database restores those files.

From a trusted machine with PHP CLI and access to the original private recovery archive and site files:

```bash
php tools/recovery-status.php /private/trasloco-backups --json
php tools/recovery-diff.php /private/trasloco-backups/recovery-example.wpress /srv/site/wp-content --json
```

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
