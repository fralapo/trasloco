# Recovery safety implementation plan

**Goal:** Prevent file recovery from widening existing POSIX permissions or exceeding its payload budget while preserving old private recovery plans.

**Architecture:** Keep the two-phase prepare/apply interface and version-1 plan format. Snapshot bytes and preserved originals share the supplied byte budget; bounded copying also detects source growth. Apply intersects the planned and current ordinary permission bits for existing files, uses 0644 for missing files, and never copies special permission bits. Legacy plans derive permissions from the current destination.

**Execution:** Inline, under the user's authorization to complete the recommended fixes autonomously. No production merge or release.

## Tasks

- [ ] Add a standalone regression fixture covering a large original with a small snapshot, aggregate and exact byte limits, restrictive and executable permissions, legacy plans and invalid modes. Run it against the baseline and capture failure.
- [ ] Modify `lib/vendor/core/archiver/class-trasloco-offline-file-recovery.php`: bounded preservation; aggregate payload count; optional validated mode metadata; private temporary copies; checked chmod before publication.
- [ ] Run smoke, translation, PHP syntax and shell checks; add regressions to quality CI and exercise permissions in the real SQL-failure recovery fixture.
- [ ] Refresh recovery instructions, README compatibility evidence and roadmap. Compare divergent branches and document module-by-module disposition before integration.
- [ ] Commit, publish a review branch and open a PR against hardening. Inspect the full PHP/database matrix and address failures. Keep main unchanged.
