# Two-phase offline wp-content file recovery

After a SQL failure, restoring the previous database alone may leave changed or missing files. The independent CLI below can recover **uploads, themes, plugins and mu-plugins from a verified recovery archive** without booting WordPress or deleting unknown files.

**Stop WordPress, PHP workers, cron, deployment agents and other writers first.** The CLI flag records the operator's confirmation; it does not automatically disable traffic. Database rollback is a **separate** operation. Save the site's current files and database through the hosting control panel before applying changes.

Prepare a new **private, non-public** workspace on a volume with adequate free space:

    php tools/recovery-files.php prepare /private/backups/recovery-123.wpress /site/wp-content /private/recovery-work-123

Preparation verifies the archive's SHA-256 sidecar, extracts eligible files into the private workspace, identifies missing/changed destination files, and saves a copy of every existing file that would be overwritten under \`originals/\`. Preparation **does not modify** the site. It aborts on unsafe symlinks, malformed archive paths, collisions or byte limits.

Review the private \`plan.json\`, confirm the archived version is correct and ensure the site is **fully offline** before applying:

    php tools/recovery-files.php apply /private/recovery-work-123 /site/wp-content --site-offline-confirmed

Application checks all staged and original checksums, rejects targets that changed since planning, uses same-directory temporary files and per-file renames, and writes a completion state to the private plan. After interruption, replaying the same plan can finish individual file replacements that already succeeded. All overwritten originals remain in \`originals/\` for manual rescue.

**Limitations:** File replacement is **not globally atomic**. A crash between file operations leaves a partial overlay. Newly imported files not present in the recovery snapshot are not deleted; they might include legitimate newer content. Database changes, WordPress core files and uploads outside wp-content are not restored here. Because this procedure can overwrite application content, do not run it on a live or changing site. Inspect \`recovery-diff.php\` after application and rehearse recovery on an isolated copy before touching production. No release claim of full-site rollback is made.
