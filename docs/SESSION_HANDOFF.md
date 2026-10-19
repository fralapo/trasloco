# Session handoff — 2026-10-19

## Current state

Trasloco 1.7.0 adds remote delivery for local content-addressed snapshots. Immutable digest-addressed objects are read-back verified, catalogs are published last, downloads validate every object before local snapshot publication, and the standard restore flow remains available. Plaintext upload requires explicit `--allow-unencrypted` consent. The 1.6.1 Agency overview remains optional and read-only. The current local changes add durable queued incremental upload/download jobs with per-object checkpoints and expired-lease replay. Local PHP syntax, translation parity, standalone checks and the WordPress 6.9.10/MariaDB 11.4 integration and recovery rehearsal pass. GitHub Actions remain disabled because the account's free minutes are exhausted; no Actions run was started.

The snapshot store uses batches of eight 4-MiB chunks, resumes from a private journal after interruption, and coordinates catalog retention with scheduled archive retention. Compaction is opt-in and recovery points are protected. Incremental remote upload/download is implemented over storage adapters with immutable digest keys, object read-back checks, catalog-last publication and verified import. Local fixtures cover idempotency and corrupt-object rejection; real provider behavior remains uncertified.

## Local validation

- Full WordPress 6.9.10 integration and recovery rehearsal passed on PHP 7.4, 8.2, 8.4 and 8.5 with local MariaDB 13.0.2.
- The standalone quality suite and full PHP syntax scan passed locally on PHP 8.5.11.
- Incremental store and WP-CLI smoke tests passed on PHP 7.4, 8.2, 8.4 and 8.5.
- On this host, PHP 8.4 in a local Docker container passed the complete standalone quality suite, full PHP syntax scan, translation parity (459 strings in Italian, Spanish, French and German), and the incremental store/CLI tests. The focused store tests verify measured archive and manifest bytes removed, positive savings after deduplication, negative savings while originals remain, preservation of a tampered source, recovery-point protection and retention of snapshot-only catalogs. The full WordPress 6.9.10 integration and disaster/recovery fixture passed on local MariaDB 11.4, including a real archive compaction followed by snapshot-only reconstruction and restore. Its repeated-export benchmark measured 12,581,472 net bytes saved (36.1%) for one controlled 34,813,552-byte backup pair. GitHub Actions was not started.
- The v1.6.0 release verification repeated the PHP 8.4 full syntax scan, 440-string translation check and every standalone test from `.github/workflows/ci.yml`; all passed. The offline file recovery test emitted three non-fatal `rmdir` cleanup warnings for its symlink fixture, and the offsite transfer test printed `Killed` while simulating an interrupted worker before reporting success. GitHub Actions was not started.
- Translation parity at the 1.5.0 release was 431 strings; the latest local check passes all 459 strings across Italian, Spanish, French and German.
- The v1.5.0 base and S3-enabled release ZIPs passed local archive integrity checks.
- The v1.6.0 base and S3-enabled packages are built from the tagged source with `tools/build-release.sh`; both pass ZIP integrity checks, and the S3 package contains the pinned runtime autoloader.
- PR #2 was reconciled to current `main`, narrowed to the Agency overview, locally verified, and squash-merged. Its merge commit includes `[skip ci]`; GitHub Actions were not run.
- The v1.6.1 base and S3-enabled packages were built from tag `v1.6.1` and passed ZIP integrity checks; the S3 package includes the pinned runtime autoloader. Both ZIPs and their SHA-256 manifest are published in the [v1.6.1 release](https://github.com/fralapo/trasloco/releases/tag/v1.6.1). The Agency page is read-only and tested with mocked responses; no real remote agency sites were contacted.
- The local WordPress integration now benchmarks two real repeated `.wpress` exports. A stable 16-MiB media file plus one changed 512-KiB media file produced 12,582,912 shared bytes and 12,581,472 net bytes saved (36.1%) after verified compaction. This controlled fixture preserves file-list lengths and does not generalize to real customer backups; fixed-size chunk reuse can decrease when preceding content shifts.
- Incremental remote transfer now has a standalone local-adapter fixture covering immutable chunk reuse, catalog-last upload, idempotent retries, exact reconstruction and rejection of corrupted objects. The complete WordPress 7.1.3/MariaDB 11.4 integration and recovery rehearsal also passed locally after checking WP-CLI upload/download and the required plaintext-consent guard. No GitHub Actions or real provider calls were made.
- Focused incremental store, remote-transfer and WebDAV adapter checks pass on local PHP 7.4, 8.2 and 8.4 runtimes. A static UI review added a translated fallback name for progress bars whose dialog has no heading; no manual browser/assistive-technology audit was performed.
- Release `v1.7.0` was built from its pushed tag. The base ZIP passed `unzip -t`; the S3 ZIP passed the same integrity check and contains `vendor-s3/autoload.php`. The published [v1.7.0 release](https://github.com/fralapo/trasloco/releases/tag/v1.7.0) includes both ZIPs and SHA-256 manifest: base `2ff0926a7a15c1ca9b74a5d77a1f71f8047eedb11e9822a96f9f16d702067941`, S3 `6b6dee50e6ac22149cd261206aad4aef9e3d8359674f9bf5787cf4a1b251f8a4`. No Actions ran.
- Durable incremental transfer jobs are now available as `wp trasloco incremental queue-upload|queue-download`. The PHP 8.4 standalone quality suite and full local WordPress 6.9.10/MariaDB 11.4 integration and recovery rehearsal passed, including plaintext consent, both queue directions, expired-lease replay, catalog publication safety and snapshot materialization. The integration used temporary LF-normalized shell copies because the checked-in scripts have Windows CRLF; those copies and containers were removed afterward. No GitHub Actions or real provider calls were made.
- These checks use local fixtures. GitHub Actions, real remote providers, real hosting environments and production durability were not tested.

## PR #2 reconciliation update — 2026-10-19

The feature branch remains historical source material because its controller, scheduler, storage and recovery implementations are superseded by the current contracts on `main`. Its deferred Agency overview is now ported locally as an opt-in, administrator-only read-only page using `TRASLOCO_AGENCY_SITES` in `wp-config.php`. Requests require public HTTPS, disable redirects, verify TLS, cap response bytes and share a ten-second page budget; the page exposes only bounded health counts. `php tests/agency-smoke.php`, the full standalone quality suite, 459-string translation parity, PHP syntax scan and WordPress 6.9.10/MariaDB 11.4 integration and recovery rehearsal pass locally in PHP 8.4. GitHub Actions was not started. Real remote-site checks and credential lifecycle review remain open.

Clone the repository and start from `main`:

```sh
git clone https://github.com/fralapo/trasloco.git
cd trasloco
git checkout main
```

The incremental workflow is implemented and covered by local smoke and WordPress integration tests. Full archives remain by default; administrators can explicitly compact one after integrity-manifest, archive-structure, catalog and whole-file verification. Restore reconstructs to a non-existing path, verifies the archive structure, and invokes the existing importer. The snapshot table has a screen-reader caption, scoped column headings and keyboard-accessible horizontal scrolling. Storage figures measure file lengths and do not claim filesystem block allocation or broad real-archive benchmark results. Full keyboard, screen-reader and contrast audits and independent security review remain open; real screen-reader coverage and provider/hosting certifications still require separate environments.

Real AWS/S3 or WebDAV certification, cross-host/NFS locking and production Apache/Nginx/IIS/WAF testing require external environments and should remain clearly marked as unverified until tested there.

## Resume on another computer

With WP-CLI and a disposable local MariaDB available, run the main integration fixture without consuming GitHub Actions minutes:

```sh
TRASLOCO_TEST_WORDPRESS_VERSION=6.9.10 \
TRASLOCO_TEST_DB_PORT=3307 \
GITHUB_WORKSPACE="$PWD" \
tests/integration.sh
```

Run the standalone checks locally with `php tools/check-translations.php` and the test commands in [`.github/workflows/ci.yml`](../.github/workflows/ci.yml). Keep commits on `main` or a short-lived `codex/` branch and do not claim external certification from local fixtures.
