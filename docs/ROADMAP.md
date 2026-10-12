# Production readiness roadmap

This branch is an active hardening effort, not a production release.

## Implemented (source-level; still requires integration tests)
- [x] Operation-scoped expiring AJAX tokens and administrator permissions
- [x] Authenticated backup downloads; Apache/IIS archive access rules
- [x] Temporary-folder access rules and Apache migration of legacy rules
- [x] Retried uploads serialized and truncated to the correct offset
- [x] Archive structural length validation
- [x] Abort failed SQL operations instead of reporting partial success as full success
- [x] CI syntax/translation checks and regression smoke tests

## Required before a production release
- [ ] Full end-to-end WordPress fixtures on Apache, Nginx and IIS
- [ ] Run import and restore with user sessions replaced mid-operation
- [ ] Test under WAF restrictions, large sites and disconnected uploads
- [ ] Recover automatically from half-completed restores or document manual restore
- [ ] Encrypt or eliminate storage of HTTP Basic credentials
- [ ] Check archive content checksums and symlink safety
- [ ] Verify Apache/IIS rule upgrades, including legacy server configs
- [x] WP-CLI opt-in retention and documented cron/rclone encrypted offsite workflow
- [ ] Native in-app scheduling, incremental backups and cloud connectors
- [ ] Integrate import/export preflight checks and guided UX
- [ ] Perform accessibility and keyboard/screen-reader audits
- [ ] Independent code review and security testing

Avoid merging into `main` until the end-to-end restore matrix passes.
