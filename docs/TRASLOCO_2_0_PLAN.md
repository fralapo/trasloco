# Trasloco 2.0 — execution plan (15 features)

Updated 2026-10-14. This plan is an implementation dependency map, not a claim that unfinished capabilities exist.

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
7. Release a 2.0 restore workflow only after actual restore verification across at least two hosting configurations.

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
- [x] Phase 0B-2B-2: bounded-time chunk hashing, remote adapters and durable retries. Private offsite downloads persist 1 MiB range checkpoints and resume after interruption. Durable transfer jobs queue uploads and downloads, renew leases, record byte progress and recover expired workers; WebDAV uploads persist verified immutable 8 MiB parts and publish a logical archive index only after all parts are complete. S3 multipart upload IDs are stored in private job checkpoints and the AWS SDK's `ListParts` response restores completed parts after a worker interruption. Local SDK and protocol fixtures and the full PHP 7.4/8.2/8.4 × MySQL 8.0/MariaDB 11.4 integration matrix pass; live provider certification remains open below.
- [ ] Remote storage increment: the bounded WebDAV adapter implements the stream contract with HTTPS/DNS pinning, conditional create-only writes, no redirects/proxies, exact byte-range reads and bounded inventory parsing. WP-CLI upload/download verifies SHA-256 sidecars, refuses replacement, requires explicit consent for plaintext uploads and retries temporary errors up to three times. Downloads resume from private checkpoints; WebDAV and S3 multipart uploads resume from provider-backed state. `wp trasloco offsite status` checks provider reachability and archive/manifest pairing; `queue-upload`, `queue-download` and their retry commands create credential-free durable jobs. Scheduled backups can opt into encrypted resumable WebDAV/S3 upload without persisting the passphrase, with daily provider checks and transition alerts. The optional AWS SDK adapter uses conditional writes and range downloads, has a local signed HTTP wire fixture and is included in the S3 release package by `tools/build-release.sh --with-s3`. Live WebDAV/S3 provider tests, other providers and incremental retention remain pending.
- [x] Direct peer pull increment: administrator-only manifest/chunk REST routes and `wp trasloco pull-site` use HTTPS, private resumable staging, whole-archive SHA-256 verification and no-overwrite publication. Local WordPress integration verifies route permissions, resume, idempotent finalization and conflicting-file refusal; a standalone transport smoke test now runs in the quality workflow. Paired-site onboarding, live cross-host/WAF testing and encrypted relay remain open; see [peer transfer operations](PEER_TRANSFER.md).
- [x] Phase 1A: create local SHA-256 sidecar manifests and add WP-CLI verification with WordPress integration test.
- [x] Phase 1B: the backup list shows completed archive verification only while the archive and manifest still match the worker-recorded file identities; pending and failed states are visible. Durable full-archive checks, verified restore rehearsals and the rendered UI regression pass in local WordPress integration.
- [x] Initial feature 14 foundation: read-only admin-scoped REST inventory with integration permission test.
- [x] Feature 14 bounded mutating API: administrators can queue durable local backups over REST, and the last 1,000 job mutation actions retain actor, action, job ID and UTC timestamp. Restore remains outside REST; the verified explicit restore workflow is retained.
- [ ] Phases 2-15 as ordered above.

- [x] Native WP-Cron automatic backup engine with job queue, SHA-256 verification, retention and admin configuration.
- [ ] Validate scheduled operation timeouts on real hosting; WAF and server cron support.

- [x] Phase 6A: standalone versioned streaming libsodium encrypted envelope with wrong-key/corruption tests.
- [x] Phase 6B encryption workflows: CLI encryption/decryption, administrator encryption and decryption of verified manual backups, and scheduled encrypted offsite delivery. The admin decrypt action authenticates the encrypted sidecar, validates the plaintext `.wpress`, writes a chunked SHA-256 manifest, refuses collisions and never accepts the passphrase in the request. Resumable handling for large web requests and complete recovery UX remain open.

- [x] Optional encryption via wp trasloco encrypt/decrypt with Argon2id and XChaCha20 secretstream (CLI and test).
- [x] In-app encryption UI for manual backup workflows. The Backups page can create and securely download a separate encrypted copy of a checksum-verified archive, then decrypt it into a new private, verified `.wpress` file using the configured worker secret. Local integration checks wrong-passphrase cleanup, collision refusal, integrity, authenticated links and rendered controls. Resumable/timeout handling for large archives and full recovery UX remain open. Scheduled encrypted WebDAV/S3 delivery and its provider/runtime guidance are also covered by integration.

- [x] Phase 2A: verified pre-import recovery snapshot before file/database replacement; on failure import aborts.
- [x] Phase 2B operator-guided rescue: `wp trasloco restore-recovery <receipt-id>` resolves and verifies the exact pending receipt's archive, shows a no-write plan by default and requires `--yes` to run the normal restore with a fresh safety snapshot. Afterward, `wp trasloco prepare-recovery-files <receipt-id> --workspace=...` stages and reviews file restoration and archive-absent quarantine from that same receipt; applying quarantine still requires inspecting the report and explicitly approving its fingerprint. The staged same-site flow is integration-tested locally. Automatic or globally atomic rollback and large-host certification remain open under P0.

- [x] Phase 10A: optional verified recovery point before core/plugin/theme updates, fail closed when backup fails.
- [ ] Phase 10B: automated rollback and cross-host upgrade checks. Scheduled backup and offsite state email alerts are configurable per installation and retry failed WordPress mail delivery up to three times; restoring a safety point still requires operator action.

- [x] Initial feature 11 integration: private read-only site-health endpoint for future agency monitoring.
- [ ] Multi-site coordinator with mutual trust, reconciliation, notifications, permissions and site onboarding.

- [x] Scheduled backup failure alert hook with administrator email notification (subject to WordPress mail delivery).
- [ ] Webhook notifications and provider delivery certification. Backup/offsite email alerts and signed HTTPS webhooks now have per-installation settings, credential-free event payloads, safe HTTP requests and three bounded WP-Cron retries per channel. Local mocked transport covers request signing and retry queueing; external endpoint and mail-provider tests remain open.

- [x] Phase 9A: read-only archive file inventory and admin REST preview with explicit database-diff limitations.
- [x] Phase 9B file checksum diff: `php tools/recovery-diff.php` compares actual bytes from a verified `.wpress` against destination `wp-content` files, with bounded inventory and no writes. Integration covers missing, modified and archive-absent paths.
- [x] Phase 9B import preflight exclusions: WP-CLI restore accepts `--exclude-paths=uploads/cache,plugins/example/generated` for exact paths or subtrees under uploads, plugins, themes and mu-plugins. The full archive inventory and path validation still run before restore; exclusions only skip extraction and never bypass validation.
- [ ] Phase 9B semantic SQL changeset preview. The read-only file comparison accepts exact relative path/subtree exclusions via `php tools/recovery-diff.php --exclude=uploads/cache`; the administrator archive preview maps recognized table operations to destination table names and existence for supported single-site prefixes. Neither feature compares live database rows or validates arbitrary SQL, so row-level changesets remain open.

- [x] Phase 8A: WP-CLI selective media/plugin/theme extraction into a new private directory without overwriting live site content.
- [ ] Phase 8B: selective database table restore, collision handling and live-site consistency checks. The single-site WP-CLI command and Backups-page UI now preview selected table replacements, require explicit confirmation, create a verified full-site recovery point, and import only recognized SQL statements for the server-validated allowlist. A database lease prevents overlapping imports and restores. Automated detection or pausing of concurrent application writes remains open; operators must pause writes to affected tables before applying the restore.

- [x] Bounded scheduled reconciliation of verification jobs, cancellation/retry and safe abandonment of crashed backups.
- [x] True byte-level continuation of interrupted local backup jobs. Export stages persist their parameters and flushed artifact lengths; expired workers resume from the committed checkpoint and truncate uncommitted trailing bytes. A bounded crash retry policy fails closed. This does not provide a transactionally consistent database snapshot while the site is accepting writes.
- [ ] Provider-specific crash replay tests against real WebDAV services.

- [x] Accessible recent job/progress dashboard and next-backup schedule indicator.
- [x] Durable offsite transfer jobs: credential-free upload/download payloads, WebDAV part replay, download range checkpoints, byte progress, lease renewal, restart reconciliation and bounded retry budget. Only adapters that explicitly support resumable writes can accept queued uploads.

- [x] Audit fix: distinguish recovery point archives by persistent filename prefix; exclude them from both automatic and CLI retention. End-to-end regression ensures a later retention run preserves the pre-import snapshot.

- [x] Audit fix: changing backup frequency replaces the old WP-Cron recurrence; integration covers daily → weekly → daily → off.

- [x] Audit fix: reuse of an idempotency key for a different job kind/archive now yields an explicit HTTP 409 conflict; integration includes cross-kind and cross-archive collisions.

- [x] Audit fix: worker progress events renew owned backup leases in hourly export pipelines, preventing healthy long exports from being marked abandoned when they report progress. Integration tests check lease extension. Does not make interrupted backups byte-resumable.

- [x] Phase 4B: monthly backup frequency as a calendar month in the WordPress timezone, including DST, with idempotent monthly job creation and cron transition tests.

- [x] Phase 1B: admin-page checksum verification moved off the synchronous archive listing into a CSRF-protected POST that queues a durable verification job; a manifest alone is explicitly labelled unverified.

- [x] Reconciliation stops retrying expired verification workers after three attempts and records a terminal failure. Crash-loop regression checks requeue, direct claims and manual retries.

- [x] Phase 1C-1: optional version-2 SHA-256 chunk manifests (4 MiB) with bounded per-chunk validation, unchanged v1 compatibility and corruption regression tests.
- [x] Phase 1C-2: chunk checkpoints are used by bounded verification workers, and new exports publish v2 chunk manifests; full WordPress integration and archive verification pass locally.

- [x] Phase 1C-2: verification jobs can yield and resume v2 4-MiB chunk checks in bounded batches, with persisted offsets, archive/manifest stability checks, lease-safe handoff and MySQL integration. No claim of resumable export yet.

- [x] Phase 1C-3: all newly exported .wpress archives publish version 2 SHA-256/chunk manifests. Original archive bytes and legacy v1 verification remain compatible. CI exercises WP-CLI export, V2 manifest, encryption round trip, full restore and recovery.

- [x] Phase 9C: read-only full header preflight before recovery snapshot or destructive import; rejects malformed/unsafe late archive entries and caps file inventory at one million entries, with malicious fixture regression.

- [x] Phase 2B-1: WordPress- and MySQL-independent PHP CLI rescue inspection and private extraction of raw package/database metadata (non-destructive, tested against traversal).
- [x] Phase 2B-2: fresh-site bootable restore and operator-driven staged SQL/file rollback are rehearsed locally. Automatic/global atomic rollback and production portability remain P0 blockers.

- [x] CLI backup/restore and SQL-failure recovery integration passes on MySQL 8.0 and MariaDB 11.4 (see [current validation evidence](ROADMAP.md)).

- [x] Read-only administrator/CLI preflight report with backup directory status, public-root risk, disk free space, required extensions and cron mode; does not return paths or secrets. A positive report is not restore certification.

- [x] CI runtime matrix: PHP 7.4, 8.2 and 8.4 crossed with MySQL 8.0 and MariaDB 11.4. This covers CLI fixtures, not cross-host HTTP/WAF or a pinned WordPress version matrix.

- [x] Phase 2B-1B: independent CLI offline staging of uploads/themes/plugins into a new private directory with bounded bytes, no overwrite and malicious-path tests; no live-file or SQL restore.

- [x] Phase 14A-2: bounded administrator REST backup inventory pagination with total/total-pages headers, stable default response and invalid query rejection.

- [x] Offline staging now removes its private files/directories on failed safety limits or interrupted reads, so a partial stage is not presented as a successful recovery copy.

- [x] Offline encrypted recovery: standalone PHP CLI decrypts authenticated .wpress.enc archives without WordPress/MySQL, validates .wpress structure, rejects existing targets and wrong keys; WP integration exercises the full round trip.

- [x] Independent offline rescue rehearsals on actual WP-CLI exports and on mandatory pre-import recovery snapshots, including target-only media checks and real SQL metadata. Output can be placed in an explicitly selected private directory without overwriting existing files.
- [x] The offline extractor is data rescue only. The separate disaster-rehearsal fixture provisions a bootable WordPress/MySQL destination and imports the archive; in-place rollback remains a different workflow.

- [x] Restoring corrupted .wpress archives: malformed dot/control/empty path components are blocked by preflight and extractor; incomplete reads and writes abort instead of being counted as successful. Added regression fixtures.

- [x] Local storage publication now fails closed on filename collisions and cleans partial plaintext temporary files; integration checks archive content remains unchanged.

- [x] Preflight rejects duplicate root restore metadata (package.json, database.sql, multisite.json and blogs.json) before creating a recovery point or overwriting the destination.

- [x] Symlink export audit: never recurse into or archive symlinks outside wp-content; defend again against post-enumeration path swapping. Linux integration checks external secret content is absent from .wpress archives. Operators must review symlinked plugin/theme deployments before relying on full-file restores.

- [x] Safe symlink policy: when linked content is encountered, a full export fails visibly instead of silently omitting required files or dereferencing secrets. Integration verifies failure, then a clean successful backup after removing test links.

- [x] Compressor now rejects files disappearing between enumeration and content copy, source seek/read failures, and empty non-EOF reads rather than publishing a misleadingly complete export. A standalone regression exercises the missing-source failure.

- [x] P0 audit fix: expired in-progress backup leases can no longer be claimed by a second worker; only non-destructive verification may reclaim an interrupted lease. MySQL/MariaDB regression covers stale leases, safe retry and terminal abandoned state.

- [x] Offline emergency recovery now rejects the same malformed path components and DEL/control characters as live import preflight, including unsafe late archive entries. Regression verifies private staging does not create partial recovery output.

- [x] P0 preflight: require nonempty root `database.sql` before destructive restore unless `package.json` explicitly has `NoDatabase=true`; reject missing/invalid root package and add full/files-only negative fixtures.
- [x] Bootable recovery and post-DDL SQL-failure rollback have an end-to-end integration rehearsal; last run locally on PHP 8.5.4, WordPress 7.1.3 and MariaDB 13.0.2. MySQL 8.0/MariaDB 11.4 and PHP 7.4/8.2/8.4 CI matrix was not rerun.

- [x] Phase 2B-2A: independent disaster-rehearsal fixture provisions fresh WordPress/MySQL, verifies archived bytes, restores users/posts/media and preserves the new site's URL, without using source WordPress runtime.
- [x] Phase 2B-2B: operator-driven same-site rollback after an induced SQL failure restores the prior database and archived files, then quarantines the exact reviewed archive-absent file. Full integration test passes locally on PHP 8.5.4 / WordPress 7.1.3 / MariaDB 13.0.2.
- [ ] Operating-system-level disaster tests, cross-host recovery and production rescue setup remain open.

- [x] P0 negative end-to-end: derive a structurally valid .wpress from a real export with only database.sql removed; attempted restore must fail at preflight with an explicit diagnostic, leaving destination URL/user/media unchanged.

- [x] Disaster recovery runbook now documents the CI-verified fresh WordPress/MySQL bootstrap path and clearly distinguishes it from offline metadata rescue and automatic rollback.
- [x] Malformed archive filenames are not included verbatim in restore exceptions or WP-CLI logs; newline/control-character fixture checks the log boundary.

- [x] Phase 2B-2B-1: filesystem recovery receipts survive database replacement, mark pending until import completes, and are testable without WordPress.
- [x] Phase 2B-2B-2: a real post-DDL SQL import failure and manual rollback from the recorded recovery archive are verified on MySQL and MariaDB in the historical CI matrix; a separate local MariaDB 13.0.2 replay also passes.

- [x] Phase 2B-2B-2A: tested independent filesystem recovery journal and offline CLI receipt discovery, requiring no WordPress/MySQL process.
- [x] Phase 2B-2B-2B: induced real SQL error after DDL/data import, preserved independent pending receipt, and operator-driven database rollback. WordPress integration passed across PHP 7.4/8.2/8.4 × MySQL 8.0/MariaDB 11.4 on commit fdd5dbfe.

- [x] Phase 2B-2C: independent CLI `tools/recovery-status.php`, retry-idempotent recovery receipts, pending-first bounded inventory, administrator-only read-only REST and admin warning panel. Regression includes anonymous permission denial and no server-path leakage.
- [x] Phase 2B-2D: bind post-restore file staging and quarantine review to the same pending receipt, write a private JSON review report and require explicit fingerprint approval before quarantine. Full local integration verifies the real SQL-failure recovery path.
- [x] Recovery audit: a failed SQL import can deactivate the Trasloco plugin before import finalization; documented safe WP-CLI reactivation, plus a real recovery rehearsal after failure.
- [ ] **P0: Globally atomic/automatic full-site rollback is not implemented.** A complete operator-driven database + file rollback is documented and integration-tested, including reviewed quarantine of archive-absent files. MySQL DDL and filesystem mutations still cannot be committed as one global transaction; unbootable WordPress and other hosting configurations require isolated recovery and operator intervention.
- [ ] P0: production-host/proxy HTTP denial tests, WooCommerce consistency during concurrent writes and multisite fidelity before production certification. Local anonymous Apache/Nginx fixtures return 403 for archives, manifests, arbitrary temporary files and plugin storage. The Export page now warns WooCommerce operators to pause order writes; order restore fixtures pass with HPOS and legacy storage. Local WordPress/MariaDB integration kills WP-CLI with SIGKILL during SQL import, confirms the independent receipt stays pending, and successfully repeats the restore; that original receipt remains available as forensic evidence.

- [x] P0 manual recovery aid: standalone, SHA-256-gated, bounded offline comparison of a recovery archive's uploads/themes/plugins/mu-plugins against actual destination bytes. Reports changed/missing/unsafe files without loading WordPress/MySQL or touching content; includes an actual post-DDL SQL error followed by simulated missing/modified-file states and operator-driven recovery.
- [x] Recovery diff remains read-only and is not itself an automatic rollback. The separate staged file recovery workflow overlays verified snapshot content and can quarantine reviewed archive-absent paths.

- [x] P0 recovery audit: after induced post-DDL SQL failure and database recovery, a target-only file may still be absent from the live site. End-to-end test detects this, independently stages the original bytes from the verified recovery archive and confirms offline diff still flags unrecovered content; no false claim of atomic rollback.

- [x] P0 read-only extension: optional bounded offline scan of destination files absent from a verified recovery archive, with separate symlink warnings, independent relative-path samples and post-SQL-failure integration. Untracked files are not proven imported-only and are never automatically deleted.
- [x] Safe staged filesystem rollback is available through `tools/recovery-files.php`; the integration rehearsal verifies file restoration plus reviewed quarantine after restoring the database.
- [ ] Full-site automatic/global atomic restore remains open; an untracked report or quarantine candidate is never treated as proof that a file came from an import.


- [x] Phase 2B-2D-1: independent two-phase, SHA-256-verified wp-content file recovery (prepare/apply) with offline confirmation, preserved replaced bytes, preflight symlink rejection, atomic per-file replacement, retry-idempotent workspace and no deletion of untracked files. Standalone and induced-SQL-failure integration regressions included.
- [x] Phase 2B-2D-2A: optional archive-absent regular-file quarantine with complete bounded inventory, full-content archive checks, exact approval fingerprint, preserved bytes and no-overwrite restore. Standalone safety tests and full local WordPress/MySQL integration pass.
- [x] Phase 2B-2D-2B: same-site recovery runbook sequences verified database restore and reviewed file quarantine; integration fixture verifies the full user/database/media/file reconciliation after an induced SQL failure. Local PHP 8.5.4 / WordPress 7.1.3 / MariaDB 13.0.2 passed; CI matrix and other hosts remain unverified.
- [x] Phase 2B-2D-2C-1: opt-in `--require-fsync` recovery mode synchronizes staged/preserved data, plan publication, destination renames and affected directory entries; strict plans fail closed when local fsync support is unavailable. Standalone test passes on the current PHP 8.5/macOS filesystem.
- [ ] Phase 2B-2D-2C-2: cross-host fencing and power-loss failure injection remain unverified. Successful OS `fsync()` calls are not proof of storage-controller or remote-filesystem durability. A local workspace lock serializes apply processes only where `flock` is honored; file and database changes are not a globally atomic transaction.

- [x] Offline recovery safety: preserve restrictive POSIX file permissions and enforce a shared payload budget for staged snapshots plus retained originals. Old v1 plans remain readable; special modes/ownership/ACLs are not restored.

See [branch reconciliation](BRANCH_RECONCILIATION.md) before porting features from PR #2.

- [x] Offline file recovery now uses a workspace-scoped exclusive process lock; concurrent apply attempts fail before mutations and can resume after lock release. This local filesystem lock does not provide cross-host/NFS fencing or power-loss durability.
