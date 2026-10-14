# Incremental backup storage design

Status: local content-addressed snapshots are implemented for WP-CLI, manual CLI backups, and opt-in scheduled backups. Export remains a full `.wpress` operation; these snapshots deduplicate identical 4-MiB chunks across retained archives and remain independently reconstructible.

## Goal

Reduce the storage and offsite transfer cost of repeated backups without changing the `.wpress` restore format or making one snapshot depend on a single parent archive. Every retained snapshot must remain independently reconstructible and verifiable after any other snapshot is pruned.

## Existing contract

- A backup is a verified, standalone `.wpress` archive with its own SHA-256 sidecar.
- The importer, offline recovery tools, peer transfer, encryption and existing remote adapters consume that archive and sidecar.
- Scheduled and manual CLI retention can prune corresponding snapshot catalogs alongside old `.wpress` files after a new backup has passed integrity verification.
- WordPress export assembles the database and content files into a sequential `.wpress` stream; it does not currently expose a stable per-file snapshot manifest.

## Required design properties

1. Preserve existing `.wpress` reads and writes. Snapshot catalogs use a separately versioned format.
2. Represent each snapshot as a complete logical catalog. A catalog may reference shared immutable objects, but must not depend on the continued existence of a particular base snapshot. This removes parent-chain ordering from restore and retention.
3. Identify objects by a cryptographic digest and verify size and digest both when inserting and when reconstructing. A corrupt or missing object must fail closed before the importer receives an archive.
4. Publish objects before the catalog, and publish the catalog atomically only after every referenced object is durable and verified. A crash may leave unreferenced objects; it must never expose a catalog that references missing data.
5. Serialize catalog publication and garbage collection. Retention computes live references from all valid catalogs, publishes the retained catalog set, and only then removes unreferenced objects. Unknown or malformed catalogs stop garbage collection.
6. Keep immutable snapshots. A source archive or published object is never modified in place, and a restore materializes a private temporary `.wpress` without changing the catalog.
7. Keep retention safety points separate from ordinary snapshots. Recovery points must not be silently removed by the incremental policy.
8. Preserve administrator visibility of logical snapshot names, dates, sizes, integrity state and the storage reclaimed by pruning.
9. Offsite transfer must be able to publish only absent immutable objects, verify them remotely, publish the catalog last, and restore from a catalog plus its referenced objects. A local implementation alone cannot certify provider consistency or durability.

## Candidate architecture

Use a private content-addressed object store and independently complete snapshot catalogs. The initial implementation should build a catalog from the exact verified archive bytes, split by a bounded chunking scheme, and retain the original archive until the catalog has been reconstructed and verified byte-for-byte. Only then may the original archive be replaced by the catalog representation. This gives the importer a normal `.wpress` file through an explicit materialization boundary.

The local storage layer exposes catalog validation, bounded object insertion, snapshot verification, materialization, and pruning. It does not change the generic `StorageAdapter`; a later adapter change can transfer missing objects and publish catalogs last.

## Local WP-CLI workflow

The local content-addressed store is available through `wp trasloco incremental` and lives in the private `.trasloco-incremental` directory inside the configured backup directory. Capture accepts only an integrity-verified and structurally valid archive. The snapshot can be checked, listed with its capture date, reconstructed into a new `.wpress` file, measured, or pruned while retaining an explicit comma-separated set:

```sh
wp trasloco incremental capture backup.wpress
wp trasloco incremental verify backup.wpress
wp trasloco incremental materialize backup.wpress --output=restored.wpress
wp trasloco incremental list
wp trasloco incremental stats
wp trasloco incremental prune --keep=backup.wpress,newer.wpress
```

The reconstructed file is compatible with the existing importer. `wp trasloco restore backup.wpress` also reconstructs a missing archive from its catalog, verifies the full digest and required restore members, then invokes the existing restore workflow. It never overwrites an existing file. The Backups page lists catalogs, logical sizes, capture dates, restore commands and a storage summary.

`wp trasloco incremental stats` reports logical snapshot bytes, object/catalog/journal bytes, remaining archive and integrity-manifest bytes, and net savings. This is measured file length, not filesystem block allocation. Positive net savings mean the measured retained files are smaller than the logical archives; the value may be negative while originals remain.

For a one-shot CLI export, `wp trasloco backup --incremental` captures the new verified archive automatically. Adding `--keep=<count>` applies retention across full archives and snapshot-only catalogs using their capture times while preserving recovery-point catalogs. Without `--keep`, no snapshots are pruned. Manual capture and pruning remain available for existing archives.

Scheduled backups can opt into the same local snapshot format from **Trasloco → Backups**. The worker verifies or creates the chunk manifest, then captures at most eight 4-MiB chunks per request. A private append-only journal lets the next leased worker resume after interruption; the catalog is published only after every chunk matches the verified manifest. Scheduled retention counts snapshot-only catalogs and full archives together, preserving recovery-point catalogs.

Full archives are retained by default. To reclaim space for one ordinary archive, run `wp trasloco incremental compact backup.wpress`. Compaction is explicit and refuses recovery points; it checks the existing integrity manifest, archive structure, catalog objects and exact whole-archive digest, then isolates and rechecks the source before removal. The catalog remains available, and `wp trasloco restore backup.wpress` reconstructs the original archive before starting the existing import; the reconstructed `.wpress` remains available afterward and can be compacted again explicitly. Compaction is not enabled automatically for scheduled or manual backups.

## Acceptance tests

- Two archives with shared content reuse verified objects while both catalogs reconstruct their exact original bytes.
- A changed or missing object rejects verification and materialization without producing a publishable `.wpress`.
- A failure before catalog publication leaves the source archive intact and no visible new snapshot.
- A failure during pruning leaves every retained catalog reconstructible; retrying garbage collection is idempotent.
- Retention never removes a recovery point and never removes an object referenced by a retained catalog.
- Concurrent create/prune workers cannot delete objects still being published or referenced.
- Existing archive verification, encryption, import, peer transfer, WebDAV/S3 transfer and legacy backup tests continue to pass.
- Storage reports measured file lengths for catalogs, objects, journals, source archives and manifests; explicit compaction reports the archive and manifest bytes removed. Synthetic shared-chunk tests verify positive net savings. A single real 35.8 MB WordPress archive was reconstructed successfully but contained no repeated chunks, so its one-snapshot store had catalog overhead rather than positive deduplication savings; broader representative backup benchmarks remain open.

## Release gates and open questions

The archive export still runs in full for each snapshot. Local content-addressed deduplication, explicit verified compaction, snapshot-only restore, administrator visibility and file-byte savings accounting are implemented and covered by standalone and WordPress integration tests. The local integration measured the removed bytes on one 35.8 MB archive; because it had no repeated chunks, this does not establish positive savings for recurring representative backups. Cross-host locks, provider-side conditional writes, remote garbage collection, power-loss durability, real-provider testing, and production restore behavior require external environments.
