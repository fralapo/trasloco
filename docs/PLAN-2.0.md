# Trasloco 2.0 — Implementation blueprint

Status: **implementation in progress, not a release**. Base: hardening branch `d8676a0`. Every feature is done only after automated tests, a WordPress fixture, negative/security tests and user-facing documentation. No speculative "complete" labels.

## Research constraints (verified 2026-10-12)

- WP-Cron is traffic-triggered; use dedicated single events for resumable slices. Recommend a real system cron for reliable SLAs: https://developer.wordpress.org/reference/functions/wp_schedule_event/ and https://developer.wordpress.org/reference/functions/wp_schedule_single_event/
- For larger queues, consider the distributed Action Scheduler library and lock semantics: https://actionscheduler.org/
- AWS SDK PHP v3 multipart uploads are required for large (>5 GB) S3 objects, and S3-compatible services differ on supported API features: https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-multipart-upload.html and https://developers.cloudflare.com/r2/api/s3/api/
- WordPress Abilities API needs WordPress >=6.9; feature-detect instead of raising plugin minimum: https://developer.wordpress.org/apis/abilities-api/
- REST endpoints must implement capabilities in `permission_callback`: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- WooCommerce HPOS has authoritative and backup order stores. Never push selected order tables blindly: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/

## Architecture and invariants

**Compat:** PHP >=7.4, WordPress >=5.5, retain existing `.wpress` reading/writing unchanged. New manifests and other metadata are **sidecars** and optional. No schema migration may make old backups unreadable. New crypt/incremental formats use explicit versioned magic and protocol IDs.

**Modules:**
- `lib/platform/...` configuration, capabilities, schema upgrades and activation hooks
- `lib/engine/...` persistent operation ledger, state machine, checkpointing, concurrency lease, retries, cancellation
- `lib/backup/...` existing full export adapter, verified manifest and incremental content catalog
- `lib/restore/...` preflight, verified recovery point, preview, selective restore, validation, rollback orchestration
- `lib/storage/...` local/remote adapter interface, encrypted streaming, multipart/resume, lifecycle/retention
- `lib/migrate/...` temporary pairing, signed transfer protocol, migration previews
- `lib/integrations/...` REST, Abilities API, WooCommerce HPOS, multisite
- `lib/admin/...` capability-checked controllers and task-first progressive interface
- `tests/...` hermetic primitives, WordPress/MySQL end-to-end, fault injection and host matrix

**Contracts:**
1. All read/write/delete operations require explicit scopes and server-side capability checks.
2. No plaintext secrets in logs/options/backups; encrypt at rest with key material external to archive, redact logs.
3. Only one destructive operation per site at a time; lock has a lease plus a fencing token and independent recovery after crash.
4. Restore may become partially applied; never report success on a failed phase; maintain destination recovery independent of restored tables.
5. Use integrity SHA-256 plus authenticated manifest signing or MAC when authenticity is required; hashes alone do **not** prove trust.
6. For remote file operations, use bounded streams; never load a multi-GB archive into PHP memory.
7. Do not trust archives, object keys, redirects, peers, web URLs, unvalidated SQL or symlinks.
8. Never silently delete backups or overwrite destination; confirmations and retention are explicit.
9. Exclude mutable WooCommerce order data from staging push unless an audited HPOS-aware reconciliation strategy is chosen.
10. Provide EN/IT/ES/FR/DE copy; keyboard, screen reader and responsive tests precede release.
11. Treat progress, errors and timestamps as durable data, not the transient browser DOM.

## Feature delivery stages

| ID | Deliverable | Technical strategy | Acceptance and negative tests | Status |
|---|---|---|---|---|
| F01 | Scheduled full backups | persistent single-event slices, WP-Cron, configurable intervals, system-cron hook, admin options, notifications | background backup without browser; no duplicate runs; failed cron retry; DST and retention | planned |
| F02 | Remote storage | `StorageAdapter`, S3 SDK multipart, R2/B2 endpoints, SFTP/WebDAV adapters, OAuth for Drive/Dropbox/OneDrive | 5GB+ streams, timeouts, retries, permissions, expired OAuth, SSRF regression | planned |
| F03 | Recovery points | non-destructive verified local snapshot of destination before restore; independent recovery bootstrap and immutable checkpoints | abort before mutations on snapshot failure; restore after table corruption | planned |
| F04 | Verified archives | SHA-256 sidecar manifest with schema, version, bytes; incremental hashing and recompute, tamper detection | valid/truncated/corrupt archives, wrong size/digest, legacy backup policy | planned |
| F05 | Resumable processing | persisted per-stage cursor, idempotency key, worker lease, progress, pause/cancel/resume | process crash, parallel workers, retries, disk exhaustion, browser closure | planned |
| F06 | Site-to-site | handshake via one-time pairing secret, TLS, bounded chunks, replay-safe transfer session | replay, expired tokens, transport loss, oversized chunks, origin validation | planned |
| F07 | Incremental/differential | versioned content catalog, file fingerprint and tombstones, database snapshot chain, retention dependencies | full+incremental reconstruction, interrupted chain, pruning validation | planned |
| F08 | Selective restore | logical file-path selection, table allowlist; dry-run preflight and conflict report | sandbox paths, safe partial DB restore, never damage WP core | planned |
| F09 | Change preview | diff of manifest/catalog, db schema and active plugin/theme metadata before confirmation | no write during preview, approximate sizes, denied resource leak | planned |
| F10 | Pre-update recovery | WordPress upgrader pre-hooks, verified backup and opt-in policy, recovery UI | update abort if backup fails, no backup recursion, rollback rehearsal | planned |
| F11 | Agency dashboard | dedicated opt-in management hub, per-site tokens, heartbeat, health status, scoped site membership | compromised client cannot access unrelated sites, revocation, pagination | planned |
| F12 | Staging | isolated clone with domain/path rewrite, selective push plan, table and order exclusions | WooCommerce orders never overwritten, links, media, cleanup | planned |
| F13 | Encrypted backups | AEAD streaming envelope, password-based key derivation, recovery key export, key rotation strategy | wrong key, tampering, partial decrypt, memory bounds | planned |
| F14 | Automation API | REST v1 readonly first, privileged POST with capability checks, Abilities API >=6.9 only | 401/403 and CSRF, pagination, rate limiting, schema validation | planned |
| F15 | Multisite & WooCommerce | network-aware tables/sites paths; HPOS store and pending sync inspection | single-site/network fixture, synchronized HPOS, unsupported scenario refusal | planned |

## Delivery order with dependencies

- **S0:** plan, build regression checks and environment fixtures before feature changes.
- **S1:** F04 integrity and manifest with independent read-only API (partial F14), verify CLI; old archives supported.
- **S2:** F05 operation ledger and lock, then F01 automatic backups and alerts; use same pipeline (no fork).
- **S3:** F03 recovery points and F09 change preview before allowing F08 selective restore.
- **S4:** F02 local/S3-compatible adapter plus F13 encryption; only then add cloud providers / OAuth connectors.
- **S5:** F06 migration protocol with resumable transport and verified checksums.
- **S6:** F07 incremental chain plus pruning dependency graph.
- **S7:** F10 update backups and F12 isolated staging.
- **S8:** F15 WooCommerce/Multisite compatibility, F11 agency hub and full F14 API.
- **S9:** independent security audit, crash/fault injection, stress test, i18n/a11y and release packaging.

## Done gate per feature

- Source/DB/file migrations reviewed; no hidden behavior changes; no destructive branch merges.
- Unit tests PHP 7.4/8.2/8.4 + WP/MySQL round-trip + negative cases green.
- Manual install and screenshots under desktop/mobile; WCAG-oriented keyboard/screen reader checks.
- Emergency recovery instructions documented; testable in clean container without production secrets.
- Explicit support matrix and known limitations; only then move status to **done**.

## Known release blockers from original hardening PR

Unverified on Apache/IIS/Nginx live configurations; multisite; WAF; huge archives; recovery after filesystem + MySQL failure. Do not merge this feature branch into main automatically.
