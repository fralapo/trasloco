# Branch reconciliation — 2026-10-13

## Decision

Use `hardening/production-readiness-2026-10` as the integration baseline. Keep `feature/trasloco-2-core` and draft PR #2 as preserved source material until its distinct features have been ported and tested individually. This document is a reconciliation plan, not a completed merge. No historical feature commits are discarded or marked delivered on hardening.

At the audited heads (`7eb0d24` and `5fe3ab8`) the branches have 107 and 30 exclusive commits respectively. A `git merge-tree --write-tree` rehearsal found eight textual conflicts: security documentation, translations, main controller, backup model, Apache and IIS access rules, integration tests and smoke tests. Automatic merges also combine loaders and CLI commands; resolving textual conflicts alone would not establish runtime compatibility.

The two branches register overlapping `/trasloco/v1/health`, `/jobs`, `/backups` and verification endpoints through different controllers. Their schedulers use different persistence and locking models. Loading both implementations would leave ambiguous route behavior and two independent backup engines. Do not merge or cherry-pick the complete legacy foundation onto hardening.

## Module disposition

| Feature branch code | Hardening baseline | Reconciliation action |
|---|---|---|
| `Trasloco_Background` (options-based jobs) | `Trasloco_Job_Store`, `Trasloco_Job_Worker`, `Trasloco_Scheduled_Backups` | Retain the tested table/lease engine. Port useful slice behavior only with crash and duplicate-worker tests. Do not activate both schedulers. |
| `Trasloco_Manifest` | `Trasloco_Integrity` v1/v2 sidecars and chunk verification | Keep hardening v1 and new-export v2 formats. The verifier and offline rescue now also accept the feature branch's `schema: 1`, `format: wpress` whole-file SHA-256 sidecars as read-only compatibility input; fixtures check valid, mismatched-name and modified-archive cases. New writes remain in the hardening format. |
| `Trasloco_Crypt` | `Trasloco_Encryption`, CLI encrypt/decrypt and independent decrypt | Compare envelope formats and supply explicit compatibility fixtures before importing remote encrypted artifacts. Do not replace the hardening envelope silently. |
| `Trasloco_Recovery`, `Trasloco_Preview` | Import preflight, verified pre-import snapshot, independent journal/diff/file rescue | Retain fail-closed checks and SQL-failure fixtures. Port only missing preview information with administrator permissions and bounded results. |
| `Trasloco_API` | `Trasloco_REST_API` and job endpoints | Keep one controller and one permission policy. The bounded preflight is now exposed as the optional WordPress 6.9+ `trasloco/site-readiness` read-only ability, reusing the existing admin permission callback and report; no duplicate REST controller or mutating ability is added. Integration checks anonymous denial and administrator execution. |
| `Trasloco_Selective` (single media restore) | Private CLI selective staging and recovery overlay | Preserve existing no-overwrite behavior; any direct live-media port must retain path, checksum, collision and symlink tests. |
| `Trasloco_Storage_Backend` / S3 / WebDAV | `Trasloco_Storage_Adapter` and hardened local writer | Adapt remote providers to the current stream contract. Test SSRF, redirects, TLS, collisions, credentials and multipart retries before enabling them. |
| `Trasloco_Peer`, transfer endpoints | No enabled peer transfer module | Preserve feature source and fixtures. Port after storage contract alignment, with strict request bounds, private staging, resumability and cross-host tests. |
| `Trasloco_Agency` and remote overview | Local administrator health API | Keep remote overview pending; audit credentials, outbound URLs and scope before adding it. |
| Feature schedule/agency templates, translations and Composer dependency | Current dashboard, translation catalog; no Composer runtime | Port only with the associated module and its tests. Avoid orphan views or optional SDK dependencies in a core safety fix. |

## Next integration gates

1. Land the isolated recovery permission/payload fixes with the complete PHP/database matrix green.
2. Port one distinct feature at a time into the baseline; remote adapters precede peer transfer.
3. For each port, test WordPress permissions and legacy artifacts, update this table and the 2.0 plan, and keep draft status until host-level checks pass.
4. Close or supersede PR #2 only after every distinct module has an explicit disposition supported by code/tests; leaving its source preserved is intentional.

Production blockers remain automatic/global full-site rollback, cross-host and power-loss recovery, production HTTP/server/WAF coverage, WooCommerce consistency during concurrent writes and multisite fidelity. Local SQL-import crash replay and staged same-site recovery have been rehearsed; they do not certify those remaining capabilities. A branch reconciliation does not certify production readiness.
