# Offline rescue extraction (disaster recovery)

**Scope:** When WordPress or its database cannot boot, `php tools/rescue-extract.php /path/to/backup.wpress` can inspect the archive and safely extract `database.sql`, `package.json`, and `multisite.json` into a new private (0700) temporary directory. **This does not restore a site or automatically roll back a broken import.**

The utility uses **only PHP CLI**. It does not load WordPress, connect to MySQL, or write to your live WordPress folders. It checks the full archive structure and validates v1/v2 SHA-256 sidecar manifests when present. A legacy archive without a manifest is explicitly reported as unverified.

1. Preserve a copy of the original `.wpress` archive and its `.wpress.manifest.json` sidecar if available, preferably **outside the document root**. Do not modify the originals.
2. From a trusted terminal, run `php tools/rescue-extract.php '/absolute/path/site.wpress'`.
3. Protect the output directory and the raw SQL; these files contain sensitive site data. Use the extracted `package.json` and SQL only as inputs to an **operator-reviewed** disaster recovery plan.
4. Restore the database manually only after checking database prefixes, URLs, serialized data and an independent target backup. Restore site files separately. If WordPress can still boot, the tested `wp trasloco restore` path is preferable.
5. If a sidecar checksum mismatches, stop: do not attempt extraction of that corrupted archive.

**Remaining blocker:** One-click disaster recovery with broken WordPress/MySQL and automatic rollback after partial SQL/file replacement is **not** provided. Those operations require tested platform-specific database recovery procedures and must not be improvised on production sites.
