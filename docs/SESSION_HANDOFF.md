# Session handoff — 2026-10-14

## Current state

Trasloco 1.5.0 is on the public `main` branch. Release [`v1.5.0`](https://github.com/fralapo/trasloco/releases/tag/v1.5.0) includes the base and S3-enabled plugin ZIPs. The source release commit is `578e71e`; the branch was pushed with `[skip ci]` because the account's free GitHub Actions minutes are exhausted. No Actions run was started for this release.

The release adds opt-in scheduled local content-addressed snapshots. Capture uses batches of eight 4-MiB chunks, resumes from a private journal after interruption, and coordinates catalog retention with scheduled archive retention. The follow-up local increment adds snapshot inventory to the Backups page, snapshot-only reconstruction through the ordinary WP-CLI restore command, explicit verified compaction, retention across full archives and compacted catalogs, and measured file-byte savings. Compaction is opt-in and recovery points are protected. Remote content-addressed delivery remains open.

## Local validation

- Full WordPress 6.9.10 integration and recovery rehearsal passed on PHP 7.4, 8.2, 8.4 and 8.5 with local MariaDB 13.0.2.
- The standalone quality suite and full PHP syntax scan passed locally on PHP 8.5.11.
- Incremental store and WP-CLI smoke tests passed on PHP 7.4, 8.2, 8.4 and 8.5.
- On this host, PHP 8.4 in a local Docker container passed the complete standalone CI suite, full PHP syntax scan, translation parity (440 strings in Italian, Spanish, French and German), and the updated incremental store/CLI tests. The focused store tests verify measured archive and manifest bytes removed, positive savings after deduplication, negative savings while originals remain, preservation of a tampered source, recovery-point protection and retention of snapshot-only catalogs. The full WordPress 6.9.10 integration and disaster/recovery fixture also passed on local MariaDB 11.4, including a real archive compaction followed by snapshot-only reconstruction and restore. GitHub Actions was not started.
- Translation parity at the 1.5.0 release was 431 strings; the latest local check passes all 440 strings across Italian, Spanish, French and German.
- Base and S3-enabled release ZIPs were built locally and passed archive integrity checks.
- These checks use local fixtures. GitHub Actions, real remote providers, real hosting environments and production durability were not tested.

## Resume on another computer

Clone the repository and start from `main`:

```sh
git clone https://github.com/fralapo/trasloco.git
cd trasloco
git checkout main
```

The incremental workflow is implemented and covered by local smoke and WordPress integration tests. Full archives remain by default; administrators can explicitly compact one after integrity-manifest, archive-structure, catalog and whole-file verification. Restore reconstructs to a non-existing path, verifies the archive structure, and invokes the existing importer. Storage figures measure file lengths and do not claim filesystem block allocation or broad real-archive benchmark results. The remaining roadmap audits include keyboard/screen-reader/contrast review and independent security review; real screen-reader coverage and provider/hosting certifications still require separate environments.

Real AWS/S3 or WebDAV certification, cross-host/NFS locking and production Apache/Nginx/IIS/WAF testing require external environments and should remain clearly marked as unverified until tested there.

With WP-CLI and a disposable local MariaDB available, run the main integration fixture without consuming GitHub Actions minutes:

```sh
TRASLOCO_TEST_WORDPRESS_VERSION=6.9.10 \
TRASLOCO_TEST_DB_PORT=3307 \
GITHUB_WORKSPACE="$PWD" \
tests/integration.sh
```

Run the standalone checks locally with `php tools/check-translations.php` and the test commands in [`.github/workflows/ci.yml`](../.github/workflows/ci.yml). Keep commits on `main` or a short-lived `codex/` branch and do not claim external certification from local fixtures.
