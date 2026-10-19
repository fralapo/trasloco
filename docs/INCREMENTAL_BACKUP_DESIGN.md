# Incremental backup storage design

Status: local content-addressed snapshots are implemented for WP-CLI, manual CLI backups, and opt-in scheduled backups. Immutable remote object/catalog transfer is implemented on the storage adapter contract and verified against a local fixture. Export remains a full `.wpress` operation; these snapshots deduplicate identical 4-MiB chunks across retained archives and remain independently reconstructible.

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

The local storage layer exposes catalog validation, bounded object insertion, snapshot verification, materialization, and pruning. Remote transfer uses the generic storage adapter with digest-addressed immutable objects (`tro-<sha256>.obj`) and archive-hash-addressed catalogs (`trc-<sha256>.json`). Upload verifies existing objects or writes and reads them back, then publishes the catalog last. Download verifies every object before atomically exposing a local catalog. Provider conditional-write, crash durability, restore, and cost behavior still need real-provider certification.

## Local WP-CLI workflow

The local content-addressed store is available through `wp trasloco incremental` and lives in the private `.trasloco-incremental` directory inside the configured backup directory. Capture accepts only an integrity-verified and structurally valid archive. The snapshot can be checked, listed with its capture date, reconstructed into a new `.wpress` file, measured, or pruned while retaining an explicit comma-separated set:

```sh
wp trasloco incremental capture backup.wpress
wp trasloco incremental verify backup.wpress
wp trasloco incremental materialize backup.wpress --output=restored.wpress
wp trasloco incremental list
wp trasloco incremental stats
wp trasloco incremental prune --keep=backup.wpress,newer.wpress
wp trasloco incremental upload backup.wpress --allow-unencrypted
wp trasloco incremental download backup.wpress
wp trasloco incremental queue-upload backup.wpress --allow-unencrypted
wp trasloco incremental queue-download backup.wpress
```

The reconstructed file is compatible with the existing importer. `wp trasloco restore backup.wpress` also reconstructs a missing archive from its catalog, verifies the full digest and required restore members, then invokes the existing restore workflow. It never overwrites an existing file. The Backups page lists catalogs, logical sizes, capture dates, restore commands and a storage summary.

`wp trasloco incremental stats` reports logical snapshot bytes, object/catalog/journal bytes, remaining archive and integrity-manifest bytes, and net savings. This is measured file length, not filesystem block allocation. Positive net savings mean the measured retained files are smaller than the logical archives; the value may be negative while originals remain.

For a one-shot CLI export, `wp trasloco backup --incremental` captures the new verified archive automatically. Adding `--keep=<count>` applies retention across full archives and snapshot-only catalogs using their capture times while preserving recovery-point catalogs. Without `--keep`, no snapshots are pruned. Manual capture and pruning remain available for existing archives.

Scheduled backups can opt into the same local snapshot format from **Trasloco → Backups**. The worker verifies or creates the chunk manifest, then captures at most eight 4-MiB chunks per request. A private append-only journal lets the next leased worker resume after interruption; the catalog is published only after every chunk matches the verified manifest. Scheduled retention counts snapshot-only catalogs and full archives together, preserving recovery-point catalogs.

Remote transfer uses the configured `local`, WebDAV, or S3 adapter. `wp trasloco incremental upload <archive> --allow-unencrypted` uploads only absent digest-addressed chunks and publishes the catalog after read-back verification; repeats are idempotent. `wp trasloco incremental download <archive>` fetches the catalog, validates it, imports its objects into the local store, and publishes the catalog only after every object and the whole reconstructed archive digest pass. Run `wp trasloco restore <archive>` to use the normal restore flow after downloading. Incremental objects contain original backup bytes, so upload is refused unless the operator explicitly supplies `--allow-unencrypted`; this workflow does not encrypt individual objects. Do not use it where remote plaintext storage is unacceptable.

The `queue-upload` and `queue-download` variants persist credential-free transfer jobs in the existing job ledger. Workers checkpoint object counts and bytes, renew their lease during long transfers, and publish the catalog only after all objects pass verification. A download reports and persists a distinct whole-archive verification phase and continues checking the lease while reading every reconstructed byte before catalog publication; it compares the published catalog with the verified remote catalog without a redundant second object scan. If a worker stops after an object is transferred, a retry verifies and reuses that immutable object before continuing; incomplete downloads never publish a catalog. Upload jobs still require explicit plaintext consent. These job paths use the configured adapter but local tests do not certify provider durability or conditional-write behavior.

The local transfer fixture uploads two snapshots with one shared 4-MiB object and confirms that the second upload sends only its new object. It also imports the catalog, reconstructs exact bytes, checks idempotent repeats, exercises cancellation callbacks before catalog publication, and rejects a corrupted remote object. The WordPress integration fixture queues both transfer directions, expires a worker lease, recovers the job and observes the persisted `verifying` checkpoint before the final verified checkpoint. These tests validate the adapter and job contracts with local storage only, not remote provider behavior.

Full archives are retained by default. To reclaim space for one ordinary archive, run `wp trasloco incremental compact backup.wpress`. Compaction is explicit and refuses recovery points; it checks the existing integrity manifest, archive structure, catalog objects and exact whole-archive digest, then isolates and rechecks the source before removal. The catalog remains available, and `wp trasloco restore backup.wpress` reconstructs the original archive before starting the existing import; the reconstructed `.wpress` remains available afterward and can be compacted again explicitly. Compaction is not enabled automatically for scheduled or manual backups.

## Acceptance tests

- Two archives with shared content reuse verified objects while both catalogs reconstruct their exact original bytes.
- A changed or missing object rejects verification and materialization without producing a publishable `.wpress`.
- A failure before catalog publication leaves the source archive intact and no visible new snapshot.
- A failure during pruning leaves every retained catalog reconstructible; retrying garbage collection is idempotent.
- Retention never removes a recovery point and never removes an object referenced by a retained catalog.
- Concurrent create/prune workers cannot delete objects still being published or referenced.
- Existing archive verification, encryption, import, peer transfer, WebDAV/S3 transfer and legacy backup tests continue to pass.
- Storage reports measured file lengths for catalogs, objects, journals, source archives and manifests; explicit compaction reports the archive and manifest bytes removed. Synthetic shared-chunk tests verify positive net savings. A single 35.8 MB WordPress archive contained no repeated chunks, so its one-snapshot store had catalog overhead. The local WordPress integration now creates two actual `.wpress` exports with a stable 16-MiB media file and a changed 512-KiB media file: the pair measured 34,813,552 logical bytes, shared 12,582,912 bytes across three chunks, and saved 12,581,472 net bytes (36.1%) after verified compaction. This controlled fixture keeps the file list and sizes stable; it does not predict savings on customer sites or when earlier archive bytes shift chunk alignment.

## Release gates and open questions

The archive export still runs in full for each snapshot. Local content-addressed deduplication, explicit verified compaction, snapshot-only restore, administrator visibility, file-byte savings accounting and remote object/catalog transfer are implemented and covered by local fixtures. The repeated-export fixture demonstrates positive savings for one controlled local media workload; more varied backup-change patterns and representative site datasets remain unmeasured. Fixed-size chunk reuse depends on matching archive offsets, so edits or new files before unchanged content can reduce reuse. Cross-host locks, provider-side conditional writes, remote garbage collection, power-loss durability, real-provider testing, and production restore behavior require external environments.
