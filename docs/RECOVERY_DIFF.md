# Offline Recovery File Comparison

After a failed WordPress restore, the recovery-point snapshot may have the original site database and files, but an interrupted import may have overwritten only some live files. Do not assume that returning the database restores those files.

From a trusted machine with PHP CLI and access to the original private recovery archive and site files:

```bash
php tools/recovery-status.php /private/trasloco-backups --json
php tools/recovery-diff.php /private/trasloco-backups/recovery-example.wpress /srv/site/wp-content --json
```

The comparison is **read only** and **does not bootstrap WordPress or MySQL**. It validates the backup's versioned SHA-256 manifest, validates archive structure and paths, checks contents in fixed-size chunks, and reports counts plus at most 25 relative file names in states `identical`, `modified`, `missing`, or `unsafe_target`. A symlink or non-file target is never traversed. Archive work is limited to 100,000 content files and 2 GiB by default; larger sites require an explicitly reviewed implementation change.

Limitations: this does not detect imported-only extra files that are not in the recovery archive, database mutations, or concurrent live writes. It **does not delete or replace anything** and is not an automated, atomic rollback. Do not use `rsync --delete` against production based only on this report. For a full rollback, stop writes and rehearse disaster recovery on an isolated site; follow the recovery runbook.

Only a backup with a valid local SHA-256 sidecar is eligible; legacy backups without sidecars are rejected. The manifest is a corruption check, not proof of who created the backup.
