# Production readiness roadmap

Updated 2026-10-14. Version 1.2.0 ships the reviewed hardening work on `main`; it is a maintenance release, not production certification. [The 2.0 execution plan](TRASLOCO_2_0_PLAN.md) contains the next feature increments; [branch reconciliation](BRANCH_RECONCILIATION.md) records how the separate draft implementation will be ported.

## Verified baseline

Release candidate `d9581af` passed [quality checks](https://github.com/fralapo/trasloco/actions/runs/38417080300) on PHP 7.4/8.2/8.4 and [six CLI integration jobs](https://github.com/fralapo/trasloco/actions/runs/38417080326) across those PHP versions with MySQL 8.0 and MariaDB 11.4. Those historical CI jobs downloaded WordPress at execution time and do not validate local changes made afterward.

On 2026-10-14, the complete CLI integration and same-site staged rollback rehearsal passed locally on PHP 8.5.4 with WordPress 7.1.3 and 6.9.10, and MariaDB 13.0.2. Optional WooCommerce 11.2.1 fixtures passed with HPOS enabled and with legacy order storage, preserving an order, refund, product line, custom metadata and order note through backup, SQL-failure recovery and restore replay. Anonymous HTTP fixtures returned 200 for a public page and 403 for an archive, its manifest, an arbitrary temporary file and plugin storage on Apache 2.4.67 and Nginx 1.31.6. These results cover local fixtures only, not production hosting or concurrent store writes.

Run the optional previous-major WordPress fixture with `TRASLOCO_TEST_WORDPRESS_VERSION=6.9.10 TRASLOCO_TEST_DB_PORT=3307 GITHUB_WORKSPACE="$PWD" tests/integration.sh` when a disposable MySQL-compatible server and WP-CLI are available. Omit the version variable to use the current Core release (7.1.3 in the local run above). The optional WooCommerce fixtures use `TRASLOCO_TEST_WOOCOMMERCE=1` (HPOS) or `TRASLOCO_TEST_WOOCOMMERCE=1 TRASLOCO_TEST_WOOCOMMERCE_HPOS=0` (legacy order storage); both install WooCommerce 11.2.1 and verify data through WooCommerce APIs.

- [x] Expiring operation-scoped tokens, administrator permissions and authenticated backup downloads.
- [x] Apache/IIS access rules for archives and private staging; Nginx configuration documented.
- [x] Encrypted HTTP Basic credentials using installation key material.
- [x] Serialized retried uploads, archive structural/path preflight, fail-closed SQL imports and symlink exports.
- [x] SHA-256 v1/v2 sidecars and resumable chunk-verification jobs.
- [x] Verification and offline-rescue compatibility for schema-1 SHA-256 sidecars from the preserved 2.0 feature branch; new archives continue to use the hardening v2 chunk format.
- [x] Persistent job ledger and leases, automatic backup settings, calendar-month scheduling, verified retention and failure notification hook.
- [x] CLI streaming encryption/decryption and independent encrypted rescue.
- [x] Read-only decryption compatibility for the preserved 2.0 branch's authenticated `TRLENC01` envelope; new encrypted backups keep the hardening `TRASENC1` format.
- [x] Verified pre-import recovery snapshots, independent receipts, clean-WordPress disaster rehearsal and manual database rollback after a real post-DDL SQL failure.
- [x] Independent two-phase file recovery with preserved originals; unknown destination files are not deleted.
- [x] Administrator preflight/dashboard, archive preview and permission-scoped REST inventory/status; WordPress 6.9+ also exposes the same bounded preflight as a read-only admin-only Abilities API ability.
- [x] WooCommerce live-export consistency warning with translation parity and rendering regressions for active/inactive WooCommerce. It advises pausing order writes; it does not create a database snapshot.

## Recovery safety increment

- [x] Preserve restrictive ordinary POSIX permissions on replaced files, including permissions tightened after planning; retain old v1 plan compatibility.
- [x] Count staged content plus preserved originals in one payload budget and bound original copies even if the source grows.
- [x] Standalone regressions and permission assertion in the post-SQL-failure integration fixture. This increment passed [quality CI on all three PHP versions](https://github.com/fralapo/trasloco/actions/runs/38415500101) and [all six PHP/database integration jobs](https://github.com/fralapo/trasloco/actions/runs/38415499626), including the private-file assertion after real SQL failure.

## Remaining production certification work

- [x] Local operator-driven full-site rollback rehearsal, including reviewed archive-absent paths. Production portability remains open and overlay recovery is not globally atomic.
- [x] Local process-lock contention regressions for browser-style export leases, WP-CLI/server backup jobs and offline file recovery; integration covers yielding and resuming one export owner while rejecting another, unsafe symbolic/hard-linked lock paths, and SIGKILL replay during SQL import.
- [ ] Cross-host/NFS fencing and storage-device power-failure injection. Local `flock` and `fsync()` tests do not prove remote filesystem or storage-controller durability.
- [ ] Real-host Apache/Nginx/IIS HTTP denial and authenticated restore tests; sessions replaced mid-import, WAF, disconnected uploads and large-host stress. Local Apache and Nginx fixture checks pass as recorded above.
- [ ] Repeat the PHP 7.4/8.2/8.4 and MySQL 8.0/MariaDB 11.4 CI matrix after local changes; the locally tested WordPress versions are 7.1.3 and 6.9.10 on PHP 8.5.4 / MariaDB 13.0.2.
- [ ] Remote storage/cloud adapters and incremental chain delivery on the canonical job/storage contracts. A bounded WebDAV stream adapter now passes local contract/input-safety tests, but is not exposed in backup or WP-CLI flows and has not been tested against a real provider; S3, retry/resume and provider integration remain open.
- [ ] Consistency of WooCommerce/HPOS data while orders or scheduled actions are changing, plus other versions and order-related extensions. Pinned WooCommerce 11.2.1 restore fixtures for HPOS and legacy order storage pass locally. Multisite remains unsupported; a regression verifies network imports fail closed before importer metadata, files or database replacement.
- [ ] Complete keyboard, screen-reader, contrast and responsive accessibility audits, plus independent security review. This local pass now traps Tab focus inside Trasloco's body-level dialogs, including when focus would otherwise reach the separate WordPress admin bar; manual browser and assistive-technology verification remains open.
- [ ] Module-by-module reconciliation of draft PR #2; textual conflict resolution alone is insufficient.

Green CLI CI is necessary but does not prove complete disaster recovery or host compatibility. Version 1.2.0 does not claim those remaining certification results.
