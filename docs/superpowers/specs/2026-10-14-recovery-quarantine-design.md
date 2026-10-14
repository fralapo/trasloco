# Offline recovery quarantine design

## Goal

Extend the existing offline file recovery workflow with an explicit, reversible way to quarantine regular files under `uploads`, `plugins`, `themes` and `mu-plugins` that are absent from a verified recovery archive. This closes one filesystem gap after a partial import without claiming an atomic full-site rollback.

## Safety boundary

An archive-absent file is only a candidate. It may have been created legitimately after the snapshot, so preparation never changes the live tree and application requires the operator to inspect the complete candidate list and pass an approval fingerprint bound to the destination, archive and candidate list. Quarantine is refused when package metadata reports content exclusions. The export records Trasloco's own plugin directory because that directory is intentionally absent from archives. The workflow copies and verifies every candidate into the private recovery workspace before removing any live copy. It refuses changed files, symlinks, unsafe paths, incomplete inventories, duplicate entries, budget overflows and unrecognized destination types. The private copies remain available for an explicit, no-overwrite restore operation.

The operation is limited to regular files in the four existing content groups. It does not remove directories, execute SQL, restore database contents, coordinate remote hosts, or guarantee power-loss durability. All site writers must be stopped. A workspace lock only serializes local processes when the filesystem honors `flock`.

## Workflow

1. `prepare` verifies the archive sidecar and stages the existing snapshot as today. With an opt-in flag, it fully inventories archive-absent regular files, records relative paths, SHA-256, byte size and ordinary mode, and copies their bytes into a private `quarantine/` tree under the same payload budget.
2. `review` prints the full list and an approval fingerprint calculated from the exact candidate records. The operator inspects every path and approves that fingerprint.
3. `apply` requires both site-offline confirmation and the exact fingerprint. It validates all snapshot files, candidates, preserved originals and destination states before the first mutation. Snapshot files are restored using existing atomic per-file replacement; candidates are removed only after their verified private copy exists. Replaying after process interruption is idempotent.
4. `restore-quarantine` uses the same approval fingerprint and workspace lock to put quarantined files back only when their original paths are vacant; it never overwrites later site data.

## Compatibility and tests

Existing calls and version-1 plans without quarantine records remain valid and retain their current behavior. Quarantine is opt-in. Standalone PHP tests cover preparation without live writes, complete review output, missing/wrong approval, changed destination rejection before mutations, successful quarantine, replay, and no-overwrite restoration. WordPress/MySQL integration remains an external gate because this environment has no Docker or WP-CLI.
