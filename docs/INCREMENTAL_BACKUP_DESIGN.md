# Incremental backup storage design

Status: design in progress. No incremental archive format is enabled by this document.

## Goal

Reduce the storage and offsite transfer cost of repeated backups without changing the `.wpress` restore format or making one snapshot depend on a single parent archive. Every retained snapshot must remain independently reconstructible and verifiable after any other snapshot is pruned.

## Existing contract

- A backup is a verified, standalone `.wpress` archive with its own SHA-256 sidecar.
- The importer, offline recovery tools, peer transfer, encryption and existing remote adapters consume that archive and sidecar.
- Retention currently deletes old `.wpress` files and their sidecars after a new backup has passed integrity verification.
- WordPress export assembles the database and content files into a sequential `.wpress` stream; it does not currently expose a stable per-file snapshot manifest.

## Required design properties

1. Preserve existing `.wpress` reads and writes. New storage metadata must use a separately versioned format.
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

The storage layer should expose bounded operations for catalog validation, object insertion, snapshot verification, materialization, and pruning. It should not change the generic `StorageAdapter` until local crash and retention semantics are proven. A later adapter change can transfer missing objects and publish catalogs last.

## Local WP-CLI workflow

The local content-addressed store is available through `wp trasloco incremental` and lives in the private `.trasloco-incremental` directory inside the configured backup directory. It does not replace or remove ordinary `.wpress` files. Capture accepts only a structurally verified archive. The snapshot can then be checked, listed, reconstructed into a new `.wpress` file, or pruned while retaining an explicit comma-separated set:

```sh
wp trasloco incremental capture backup.wpress
wp trasloco incremental verify backup.wpress
wp trasloco incremental materialize backup.wpress --output=restored.wpress
wp trasloco incremental list
wp trasloco incremental prune --keep=backup.wpress,newer.wpress
```

The reconstructed file is compatible with the existing importer. Local snapshots have their own retention policy, separate from deletion of ordinary archives, so pruning requires at least one explicitly retained snapshot.

## Acceptance tests

- Two archives with shared content reuse verified objects while both catalogs reconstruct their exact original bytes.
- A changed or missing object rejects verification and materialization without producing a publishable `.wpress`.
- A failure before catalog publication leaves the source archive intact and no visible new snapshot.
- A failure during pruning leaves every retained catalog reconstructible; retrying garbage collection is idempotent.
- Retention never removes a recovery point and never removes an object referenced by a retained catalog.
- Concurrent create/prune workers cannot delete objects still being published or referenced.
- Existing archive verification, encryption, import, peer transfer, WebDAV/S3 transfer and legacy backup tests continue to pass.
- The implementation measures actual byte savings on representative WordPress archives and reports when changed archive metadata prevents deduplication.

## Release gates and open questions

The archive export still runs in full for each snapshot, so this design first reduces retained and transferred bytes; it does not claim to make WordPress database export incremental. Chunk boundaries must be selected from measurements against real archives. The catalog and object store need explicit size, count and disk-budget limits before implementation. Local tests can prove reconstruction, crash replay, and retention on the current filesystem. Cross-host locks, provider-side conditional writes, remote garbage collection, power-loss durability, and production restore behavior require external environments.
