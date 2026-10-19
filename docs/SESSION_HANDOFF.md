# Session handoff — 2026-10-19

## Current state

Trasloco 1.6.1 follows the local snapshot workflow completed in 1.6.0 and adds an optional, read-only Agency overview for separately configured WordPress sites. The snapshot inventory remains visible and keyboard-scrollable in the Backups page, compacted snapshots restore through the ordinary WP-CLI command, explicit compaction verifies before removing an archive, and storage/retention accounting includes snapshot-only catalogs. Full archives remain the default. The local PHP 8.4 release suite, including all standalone checks and a full PHP syntax scan, passes. The account's free GitHub Actions minutes are exhausted, so no Actions run should be started.

The snapshot store uses batches of eight 4-MiB chunks, resumes from a private journal after interruption, and coordinates catalog retention with scheduled archive retention. Compaction is opt-in and recovery points are protected. Remote content-addressed delivery remains open.

## Local validation

- Full WordPress 6.9.10 integration and recovery rehearsal passed on PHP 7.4, 8.2, 8.4 and 8.5 with local MariaDB 13.0.2.
- The standalone quality suite and full PHP syntax scan passed locally on PHP 8.5.11.
- Incremental store and WP-CLI smoke tests passed on PHP 7.4, 8.2, 8.4 and 8.5.
- On this host, PHP 8.4 in a local Docker container passed the complete standalone quality suite, full PHP syntax scan, translation parity (440 strings in Italian, Spanish, French and German), and the incremental store/CLI tests. The focused store tests verify measured archive and manifest bytes removed, positive savings after deduplication, negative savings while originals remain, preservation of a tampered source, recovery-point protection and retention of snapshot-only catalogs. The full WordPress 6.9.10 integration and disaster/recovery fixture also passed on local MariaDB 11.4, including a real archive compaction followed by snapshot-only reconstruction and restore. GitHub Actions was not started.
- The release verification on this checkout repeated the PHP 8.4 full syntax scan, 440-string translation check and every standalone test from `.github/workflows/ci.yml`; all passed. The offline file recovery test emits three non-fatal `rmdir` cleanup warnings for its symlink fixture, and the offsite transfer test prints `Killed` while simulating an interrupted worker before reporting success. GitHub Actions was not started.
- Translation parity at the 1.5.0 release was 431 strings; the latest local check passes all 459 strings across Italian, Spanish, French and German.
- The v1.5.0 base and S3-enabled release ZIPs passed local archive integrity checks.
- The v1.6.0 base and S3-enabled packages are built from the tagged source with `tools/build-release.sh`; both pass ZIP integrity checks, and the S3 package contains the pinned runtime autoloader.
- PR #2 was reconciled to current `main`, narrowed to the Agency overview, locally verified, and squash-merged. Its merge commit includes `[skip ci]`; GitHub Actions were not run.
- v1.6.1 release packages must be built from the final version tag and checked before publishing. The Agency page is read-only and tested with mocked responses; no real remote agency sites were contacted.
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
