# Trasloco 1.8.5

## Fixes

- Authenticate resumable SHA-256 manifest checkpoints with the WordPress authentication salt. A modified compression state now fails closed before resume or manifest publication.
- Migrate checkpoints created by Trasloco 1.8.4 by replaying their committed archive prefix once and checking each chunk against the private digest journal. Migrated and newly created checkpoints resume in bounded batches without rescanning completed prefixes.
- Add regression coverage for authenticated state tampering, legacy checkpoint migration, legacy tampering, interrupted journal tails, source changes and exact manifest output.

## Local verification

- PHP 8.4: complete standalone quality suite, full PHP syntax scan and translation parity (459 strings).
- PHP 7.4 and 8.2: focused resumable manifest checkpoint tests.
- GitHub Actions were not run. Real hosting and provider certifications are outside this release's local verification.

## Operational note

Changing WordPress authentication salts while a manifest job is in progress invalidates checkpoints already authenticated by Trasloco 1.8.5. Legacy 1.8.4 checkpoints can be migrated after the change because they are verified against their archive and digest journal before signing.
