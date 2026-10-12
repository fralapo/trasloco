# Safe restore and recovery points

WP-CLI `wp trasloco restore backup.wpress` now previews the source, creates a new independent *destination* backup, hashes it, and verifies the resulting manifest before any target database/files are replaced. If this step fails the restore is aborted.

After a failed restore, locate the recovery archive under the configured backup directory and follow a separate restore of that archive. Consider using a temporary isolated WordPress installation first if the current site cannot boot. Never run a second restore blindly while the first remains active.

**CLI emergency opt-out:** `--skip-recovery-point` is supported for environments with externally verified offsite backups; this is unsafe otherwise.

Limits: this is **not** atomic rollback across database DDL and filesystem, does not yet protect the AJAX import flow, and does not guarantee restoration after PHP/site boot failure. Test on staging before production migrations.
