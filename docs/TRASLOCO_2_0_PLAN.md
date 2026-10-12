# Trasloco 2.0 — execution plan (15 features)

Updated 2026-10-12. This plan is an implementation dependency map, not a claim that unfinished capabilities exist.

## Ground rules and non-negotiable release gates

- Keep `main` at its published release. Deliver code to feature branches and reviewed PRs.
- Preserve .wpress read/write compatibility; version new manifests independently and never silently break existing backups.
- A successful backup means independently verified restore, not simply writing an archive.
- Never experiment on a live destination to validate a destructive import. Recovery needs an independent copy.
- Never store cloud credentials or encryption keys in logs, archives or source control. Support key rotation and revocation.
- Gate each feature with CLI unit tests, WordPress/MySQL integration, and permission/CSRF tests; run browser tests for changed UI.
- Cover PHP 7.4/8.2/8.4, WordPress current stable and previous major, MySQL/MariaDB. Test Apache, Nginx and relevant IIS configurations with real HTTP requests.
- All external requests use HTTPS and certificate verification; outbound site-to-site connections must prevent SSRF against localhost, private networks, rebinding and redirects.
- Explicitly mark WooCommerce order-sensitive and Multisite workflows experimental until data-consistency tests pass.

## Core technical decisions

- **Job engine:** persistent small job rows using a custom WordPress table, atomic compare-and-swap leases, lease expiry, idempotency keys, structured stage/progress/errors, cancellation between checkpoints. Prefer Action Scheduler when present or bundled and tested; implement resilient WP-Cron fallback and document system cron for low-traffic sites. Never base resumability on browser state.
- **Storage:** `StorageAdapter` contracts: put-stream/get-stream/range/list/delete/health; local filesystem first, then S3-compatible, SFTP, WebDAV, OAuth cloud connectors. No direct public archive URL.
- **Integrity:** sidecar manifest version 1 with SHA-256, byte count, timestamps, source identity and file format. Verify against actual bytes in constant memory. Adopt optional signed manifests for remote repositories; encryption with versioned AEAD envelope.
- **Restore:** phased preflight, maintenance lock, isolated safety snapshot, manifest verification, atomic file staging when supported, SQL prepare/validate, commit, postflight and recovery instructions. MySQL DDL is not transactional; a full rollback requires separate recoverable data.
- **API:** /trasloco/v1 routes with WP permissions, bounded pagination, `permission_callback`, REST nonce for browser use and Application Passwords for external use. Follow WordPress Abilities API where available; no privilege escalation through generic bearer keys.
- **Frontend:** progressive enhancement in WP admin, semantic UI, keyboard navigation, visible job state and clear warnings. Keep EN/IT/ES/FR/DE translation parity.
- **Commercial constraints:** all core features free and OSS; no mandatory accounts or remote telemetry.

## Ordered deliverables

| Phase | Feature | Core deliverables | Acceptance |
|---|---|---|---|
| 0 | Platform foundation | storage and job contracts, manifest, test fixtures, browser smoke | crash recovery and scoped authorization test |
| 1 | (4) Backup verification | SHA-256 manifest, corruption reporting, CLI+UI verify, restore rehearsal harness | mismatched byte fails; good archive passes |
| 2 | (3) Recovery Point | pre-restore snapshot and independent rescue routine | induced SQL failure followed by successful recovery |
| 3 | (5) Durable resumability | checkpoints, leases, duplicate request protection, pause/resume | interrupted browser and PHP process resume correctly |
| 4 | (1) Schedules | hourly/daily/weekly/monthly rules, timezone, retention, notifications | schedule fires once per interval and survives traffic gaps |
| 5 | (2) Cloud destinations | S3/R2/B2, SFTP, WebDAV, Google Drive/Dropbox/OneDrive providers | upload, list, verify, download, restore and credential rotation |
| 6 | (13) Encryption | optional AEAD encryption and scoped key handling | wrong key rejects without leaking plaintext |
| 7 | (6) Direct site transfer | paired connections, chunked encrypted relay/direct transport | staged cross-domain migration, dropped-link resume |
| 8 | (8) Selective restore | granular manifests, DB/media/plugin selectors | unrelated target data unchanged |
| 9 | (9) Preflight diff | SQL and file inventory diff, exclusions and compatibility warnings | deterministic review before destructive action |
| 10 | (10) Update safety | snapshot policy and update hooks | failure recovery works, no recursive backups |
| 11 | (7) Incremental/differential | content hashes, catalogs, referenced chains, pruning | corruption/retention cannot orphan restore chain |
| 12 | (12) Staging | isolated clone, selective deployment, production change guards | no overwriting live store orders |
| 13 | (15) Multisite/WooCommerce | network-aware mapping and HPOS tests | network+subsite and order fidelity proven |
| 14 | (14) Automation API | scoped REST operations, audit and integration examples | credentials revocable, unauthorized mutations denied |
| 15 | (11) Agency console | opt-in multi-site coordinator, per-site health and alerts | least-privileged onboarding, no central password storage |

Engineering process reference: [Trasloco engineering playbook](ENGINEERING_WORKFLOW.md), adapted from [ECC](https://github.com/affaan-m/ECC). The external repository provides guidance only; it is not a plugin dependency.

## Implementation protocol for every phase
1. Design contract, failure cases, data retention and privilege boundary.
2. Write fixtures/tests, including at least one deliberate failure scenario.
3. Implement backend with feature flag where migrations and storage contracts change.
4. Integrate admin UX and the translation catalog, including accessibility.
5. Run quality + full WordPress integration and HTTP security checks.
6. Ship atomic commit, update tests/docs/roadmap and include test evidence in PR.
7. Release only after actual restore verification across at least two hosting configurations.

## Research references and reasons

- Action Scheduler provides durable queued actions for WordPress and is widely used by WooCommerce: https://wordpress.org/plugins/action-scheduler/
- Authenticated REST routes require `permission_callback`: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- External API credentials should use revocable WordPress Application Passwords: https://developer.wordpress.org/advanced-administration/security/application-passwords/
- WordPress Abilities API can expose capabilities selectively: https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/
- These are engineering design references; they are not evidence that every host meets the requirements.

## Progress
- [x] Foundation hardening from PR #1: operation tokens, private download, first end-to-end CI, core UX.
- [x] Phase 0A: documented architecture and persistent-work design.
- [x] Phase 0B-1: persistent job ledger, idempotent insertion and atomic owner leases, tested on MySQL.
- [x] Phase 0B-2A: queued verification worker with leases, checkpoint, REST status/cancel and scheduled execution.
- [x] Phase 0B-2B-1: common stream adapter with tested local atomic writer and path/symlink defenses.
- [ ] Phase 0B-2B-2: bounded-time chunk hashing, remote adapters, durable retries.
- [x] Phase 1A: create local SHA-256 sidecar manifests and add WP-CLI verification with WordPress integration test.
- [ ] Phase 1B: verified UI, restore rehearsals and full archive content checks.
- [x] Initial feature 14 foundation: read-only admin-scoped REST inventory with integration permission test.
- [ ] Feature 14 mutating API (only after durable job engine and audit log).
- [ ] Phases 2-15 as ordered above.

- [x] Native WP-Cron automatic backup engine with job queue, SHA-256 verification and retention (UI to follow).
- [ ] Validate scheduled operation timeouts on real hosting; WAF and server cron support.

- [x] Phase 6A: standalone versioned streaming libsodium encrypted envelope with wrong-key/corruption tests.
- [ ] Phase 6B: CLI and UI workflows, storage protection and recovery integration.

- [x] Optional encryption via wp trasloco encrypt/decrypt with Argon2id and XChaCha20 secretstream (CLI and test).
- [ ] In-app encryption UI and encrypted cloud storage integration.

- [x] Phase 2A: verified pre-import recovery snapshot before file/database replacement; on failure import aborts.
- [ ] Phase 2B: independent one-click rescue handler and tested true rollback, including large hosting environments.

- [x] Phase 10A: optional verified recovery point before core/plugin/theme updates, fail closed when backup fails.
- [ ] Phase 10B: automated rollback, failure alerts and cross-host upgrade checks.

- [x] Initial feature 11 integration: private read-only site-health endpoint for future agency monitoring.
- [ ] Multi-site coordinator with mutual trust, reconciliation, notifications, permissions and site onboarding.

- [x] Scheduled backup failure alert hook with administrator email notification (subject to WordPress mail delivery).
- [ ] Per-site notification settings, webhook integrations and delivery retries.

- [x] Phase 9A: read-only archive file inventory and admin REST preview with explicit database-diff limitations.
- [ ] Phase 9B: true file checksum diff, SQL changeset preview and selective preflight exclusions.

- [x] Phase 8A: WP-CLI selective media/plugin/theme extraction into a new private directory without overwriting live site content.
- [ ] Phase 8B: selective database table restore, UI, collision handling and live-site consistency checks.

- [x] Bounded scheduled reconciliation of verification jobs, cancellation/retry and safe abandonment of crashed backups.
- [ ] True byte-level continuation of interrupted backups, resumable multipart remote uploads and crash replay tests.

- [x] Accessible recent job/progress dashboard and next-backup schedule indicator.

- [x] Audit fix: distinguish recovery point archives by persistent filename prefix; exclude them from both automatic and CLI retention. End-to-end regression ensures a later retention run preserves the pre-import snapshot.
