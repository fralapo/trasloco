# Recovery safety implementation plan

**Goal:** Prevent file recovery from widening existing POSIX permissions or exceeding its payload budget while preserving old private recovery plans.

**Architecture:** Keep the two-phase prepare/apply interface and version-1 plan format. Snapshot bytes and preserved originals share the supplied byte budget; bounded copying also detects source growth. Apply intersects the planned and current ordinary permission bits for existing files, uses 0644 for missing files, and never copies special permission bits. Legacy plans derive permissions from the current destination.

**Execution:** Inline, under the user's authorization to complete the recommended fixes autonomously. No production merge or release.

## Tasks

- [x] Add a standalone regression fixture covering a large original with a small snapshot, aggregate and exact byte limits, restrictive and executable permissions, legacy plans and invalid modes. Run it against the baseline and capture failure.
- [x] Modify `lib/vendor/core/archiver/class-trasloco-offline-file-recovery.php`: bounded preservation; aggregate payload count; optional validated mode metadata; private temporary copies; checked chmod before publication.
- [x] Run smoke, translation, PHP syntax and shell checks; add regressions to quality CI and exercise permissions in the real SQL-failure recovery fixture.
- [x] Refresh recovery instructions, README compatibility evidence and roadmap. Compare divergent branches and document module-by-module disposition before integration.
- [x] Commit, publish a review branch and open a PR against hardening. Inspect the full PHP/database matrix and address failures. Keep main unchanged.

## Validation evidence

- Baseline regression failed with `Original copies exceeded the payload budget without rejection`.
- Local PHP 8.5.4: recovery and archive/security smoke tests, 346 translation strings, all PHP syntax, integration shell syntax and whitespace checks passed.
- [Quality CI](https://github.com/fralapo/trasloco/actions/runs/38415500101): PHP 7.4/8.2/8.4 all passed.
- [WordPress integration](https://github.com/fralapo/trasloco/actions/runs/38415499626): all six PHP × MySQL/MariaDB jobs passed, including private permissions after real SQL failure.
- [PR #3](https://github.com/fralapo/trasloco/pull/3) delivers the isolated increment against hardening. Full integration of the divergent feature branch remains pending under `docs/BRANCH_RECONCILIATION.md`; production rollback certification is outside this increment.
