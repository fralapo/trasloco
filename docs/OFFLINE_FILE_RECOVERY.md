# Two-phase offline wp-content file recovery

After a SQL failure, restoring the previous database alone may leave changed or missing files. The independent CLI below can recover **uploads, themes, plugins and mu-plugins from a verified recovery archive** without booting WordPress. Archive-absent files are left alone unless an operator explicitly reviews and approves a reversible quarantine plan.

**Stop WordPress, PHP workers, cron, deployment agents and other writers first.** The CLI flag records the operator's confirmation; it does not automatically disable traffic. Database rollback is a **separate** operation. Save the site's current files and database through the hosting control panel before applying changes.

Prepare a new **private, non-public** workspace on a volume with adequate free space:

    php tools/recovery-files.php prepare /private/backups/recovery-123.wpress /site/wp-content /private/recovery-work-123

Preparation verifies the archive's SHA-256 sidecar, extracts eligible files into the private workspace, identifies missing/changed destination files, and saves a copy of every existing file that would be overwritten under \`originals/\`. Preparation **does not modify** the site. Its default 2 GiB payload budget covers all staged snapshot bytes plus every preserved original, including originals larger than their archived replacement. `payload_bytes` reports this total; `change_bytes` reports replacement bytes only. Plan JSON, filesystem metadata and temporary replacement files require additional free space. An over-budget preparation publishes no applicable plan; private diagnostic staging may remain and can be removed after inspection. It aborts on unsafe symlinks, malformed archive paths, collisions or byte limits.

### Optional operator-reviewed quarantine of archive-absent files

An archive-absent file is only a candidate: it may have been created legitimately after the recovery snapshot. By default it is never changed. Quarantine is refused when the archive metadata says media, themes, plugins, mu-plugins, inactive plugins/themes or cache were excluded, because absence would then be intentional. Custom paths excluded through `trasloco_exclude_content_from_export` are recorded in `package.json` and left in place. Trasloco's own plugin directory is kept in place because exports omit it. To include a bounded inventory of regular files under `uploads/`, `themes/`, `plugins/` and `mu-plugins/`, opt in during preparation:

    php tools/recovery-files.php prepare /private/recovery.wpress /site/wp-content /private/workspace --include-untracked
    php tools/recovery-files.php review /private/workspace

Review the complete `quarantine_files` list printed by `review`. The `approval_sha256` value binds approval to the exact destination root, recovery archive checksum, and every candidate relative path, file checksum, size and ordinary permission mode. It is an operator-confirmation token, not a cryptographic signature. Preparation copies candidates into the private workspace and verifies those copies without changing the live site.

Stop every site writer and inspect the complete list before passing that exact fingerprint to apply:

    php tools/recovery-files.php apply /private/workspace /site/wp-content --site-offline-confirmed --approve-untracked=<approval_sha256>

Apply validates all planned snapshot files and quarantine candidates before its first site mutation. It refuses a changed candidate, missing/corrupt private copy, unsafe entry, stale approval or incomplete inventory. It first restores snapshot files, then removes approved candidates only after their private verified copy exists. A process interruption can be resumed with the same command and fingerprint. Quarantined bytes remain in the workspace and can be restored to vacant original paths, without overwriting newer site data:

    php tools/recovery-files.php restore-quarantine /private/workspace /site/wp-content --approve-untracked=<approval_sha256>

Only regular files are quarantined; empty directories remain. This workflow does not execute SQL, coordinate independent hosts, guarantee power-loss durability or make database and filesystem changes globally atomic. A local `flock` works only on filesystems that honor its semantics. The operator must stop all site writers and separately restore the database.

Review the private \`plan.json\`, confirm the archived version is correct and ensure the site is **fully offline** before applying:

    php tools/recovery-files.php apply /private/recovery-work-123 /site/wp-content --site-offline-confirmed

Application takes a non-blocking exclusive lock in the private workspace; a second process exits with a clear error before changing the plan or destination. Symbolic and hard-linked lock files are rejected before their permissions can affect another file. The lock releases automatically on process exit. Application checks all staged and original checksums, rejects targets that changed since planning, uses same-directory temporary files and per-file renames, and writes a completion state to the private plan. After interruption, replaying the same plan can finish individual file replacements that already succeeded. On POSIX systems, existing files retain their ordinary permission bits; permissions tightened after preparation are also respected. Missing files use 0644. Special permission bits, ownership, ACLs and extended attributes are not restored. Legacy version-1 plans without permission metadata retain the current destination file permissions. Temporary copies stay private until publication, and a permission-setting failure stops recovery. All overwritten originals remain in \`originals/\` for manual rescue.

**Limitations:** File replacement is **not globally atomic**. A crash between file operations leaves a partial overlay. Newly imported files not present in the recovery snapshot are not deleted; they might include legitimate newer content. Database changes, WordPress core files and uploads outside wp-content are not restored here. Because this procedure can overwrite application content, do not run it on a live or changing site. Inspect \`recovery-diff.php\` after application and rehearse recovery on an isolated copy before touching production. No release claim of full-site rollback is made.
