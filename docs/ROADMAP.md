# Production readiness roadmap

Updated 2026-10-14. Version 1.2.0 ships the reviewed hardening work on `main`; it is a maintenance release, not production certification. [The 2.0 execution plan](TRASLOCO_2_0_PLAN.md) contains the next feature increments; [branch reconciliation](BRANCH_RECONCILIATION.md) records how the separate draft implementation will be ported.

## Verified baseline

Release candidate `d9581af` passed [quality checks](https://github.com/fralapo/trasloco/actions/runs/38417080300) on PHP 7.4/8.2/8.4 and [six CLI integration jobs](https://github.com/fralapo/trasloco/actions/runs/38417080326) across those PHP versions with MySQL 8.0 and MariaDB 11.4. The integration downloads WordPress at execution time; this is not a pinned current/previous-major WordPress matrix or an HTTP hosting test.

On 2026-10-14, the complete CLI integration and same-site staged rollback rehearsal passed locally on PHP 8.5.4, WordPress 7.1.3 and MariaDB 13.0.2. Separate anonymous HTTP fixtures returned 200 for a public page and 403 for an archive, its manifest, an arbitrary temporary file and plugin storage on Apache 2.4.67 and Nginx 1.31.6. The Nginx fixture used the rules documented in [security guidance](SECURITY.md); this does not certify a hosting provider, reverse proxy, WAF, IIS or production virtual host.

- [x] Expiring operation-scoped tokens, administrator permissions and authenticated backup downloads.
- [x] Apache/IIS access rules for archives and private staging; Nginx configuration documented.
- [x] Encrypted HTTP Basic credentials using installation key material.
- [x] Serialized retried uploads, archive structural/path preflight, fail-closed SQL imports and symlink exports.
- [x] SHA-256 v1/v2 sidecars and resumable chunk-verification jobs.
- [x] Persistent job ledger and leases, automatic backup settings, calendar-month scheduling, verified retention and failure notification hook.
- [x] CLI streaming encryption/decryption and independent encrypted rescue.
- [x] Verified pre-import recovery snapshots, independent receipts, clean-WordPress disaster rehearsal and manual database rollback after a real post-DDL SQL failure.
- [x] Independent two-phase file recovery with preserved originals; unknown destination files are not deleted.
- [x] Administrator preflight/dashboard, archive preview and permission-scoped REST inventory/status.

## Recovery safety increment

- [x] Preserve restrictive ordinary POSIX permissions on replaced files, including permissions tightened after planning; retain old v1 plan compatibility.
- [x] Count staged content plus preserved originals in one payload budget and bound original copies even if the source grows.
- [x] Standalone regressions and permission assertion in the post-SQL-failure integration fixture. This increment passed [quality CI on all three PHP versions](https://github.com/fralapo/trasloco/actions/runs/38415500101) and [all six PHP/database integration jobs](https://github.com/fralapo/trasloco/actions/runs/38415499626), including the private-file assertion after real SQL failure.

## Remaining production certification work

- [x] Local operator-driven full-site rollback rehearsal, including reviewed archive-absent paths. Production portability remains open and overlay recovery is not globally atomic.
- [ ] Multi-process locking and storage-device power-failure behavior. A local integration now sends SIGKILL to WP-CLI while MySQL is executing the imported SQL, confirms the crash receipt remains pending, then successfully repeats the restore; the original pending receipt remains as a forensic record.
- [ ] Real-host Apache/Nginx/IIS HTTP denial and authenticated restore tests; sessions replaced mid-import, WAF, disconnected uploads and large-host stress. Local Apache and Nginx fixture checks pass as recorded above.
- [ ] Pinned current/previous-major WordPress compatibility fixtures.
- [ ] Remote storage/cloud adapters and incremental chain delivery on the canonical job/storage contracts.
- [ ] WooCommerce order consistency and multisite fidelity.
- [ ] Accessibility, keyboard/screen-reader audits and independent security review.
- [ ] Module-by-module reconciliation of draft PR #2; textual conflict resolution alone is insufficient.

Green CLI CI is necessary but does not prove complete disaster recovery or host compatibility. Version 1.2.0 does not claim those remaining certification results.
