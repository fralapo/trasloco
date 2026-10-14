# Session handoff — 2026-10-14

## Current state

Trasloco 1.5.0 is on the public `main` branch. Release [`v1.5.0`](https://github.com/fralapo/trasloco/releases/tag/v1.5.0) includes the base and S3-enabled plugin ZIPs. The source release commit is `578e71e`; the branch was pushed with `[skip ci]` because the account's free GitHub Actions minutes are exhausted. No Actions run was started for this release.

The release adds opt-in scheduled local content-addressed snapshots. Capture uses batches of eight 4-MiB chunks, resumes from a private journal after interruption, and coordinates catalog retention with scheduled archive retention. Recovery-point catalogs are preserved. The ordinary `.wpress` archives remain in place, so snapshot-only restore, replacement of full archives, disk reclamation and remote object delivery are still open.

## Local validation

- Full WordPress 6.9.10 integration and recovery rehearsal passed on PHP 7.4, 8.2, 8.4 and 8.5 with local MariaDB 13.0.2.
- The standalone quality suite and full PHP syntax scan passed locally on PHP 8.5.11.
- Incremental store and WP-CLI smoke tests passed on PHP 7.4, 8.2, 8.4 and 8.5.
- Translation parity passed: 431 strings across Italian, Spanish, French and German.
- Base and S3-enabled release ZIPs were built locally and passed archive integrity checks.
- These checks use local fixtures. GitHub Actions, real remote providers, real hosting environments and production durability were not tested.

## Resume on another computer

Clone the repository and start from `main`:

```sh
git clone https://github.com/fralapo/trasloco.git
cd trasloco
git checkout main
```

The next local priority is to complete the incremental workflow end to end: make snapshot-only catalogs visible and restorable, add safe replacement of ordinary archives only after verified materialization, and test actual retained-byte savings. Update [`INCREMENTAL_BACKUP_DESIGN.md`](INCREMENTAL_BACKUP_DESIGN.md), [`AUTOMATION.md`](AUTOMATION.md) and [`ROADMAP.md`](ROADMAP.md) as that work lands.

Other useful local work includes the remaining automated keyboard/responsive accessibility audit and a focused security review. Real AWS/S3 or WebDAV certification, cross-host/NFS locking and production Apache/Nginx/IIS/WAF testing require external environments and should remain clearly marked as unverified until tested there.

With WP-CLI and a disposable local MariaDB available, run the main integration fixture without consuming GitHub Actions minutes:

```sh
TRASLOCO_TEST_WORDPRESS_VERSION=6.9.10 \
TRASLOCO_TEST_DB_PORT=3307 \
GITHUB_WORKSPACE="$PWD" \
tests/integration.sh
```

Run the standalone checks locally with `php tools/check-translations.php` and the test commands in [`.github/workflows/ci.yml`](../.github/workflows/ci.yml). Keep commits on `main` or a short-lived `codex/` branch and do not claim external certification from local fixtures.
