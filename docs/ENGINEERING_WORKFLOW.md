# Engineering playbook — Trasloco

## External methodology reference

The project may draw **engineering practices**, not executable dependencies, from the open-source [ECC (Everything Claude Code) repository](https://github.com/affaan-m/ECC). Its tooling primarily targets agent harnesses; it is not a WordPress/PHP runtime dependency. Do not install hooks, agents, MCP servers or npm packages automatically as part of plugin development or deployment.

Relevant source skills (read before applying them to the relevant change):
- [change-impact](https://github.com/affaan-m/ECC/blob/main/.agents/skills/change-impact/SKILL.md): map callers, persistence and schema changes, migration compatibility and rollback before editing.
- [tdd-workflow](https://github.com/affaan-m/ECC/blob/main/.agents/skills/tdd-workflow/SKILL.md): write a failing regression test, then implement; cover success and failure cases.
- [security-review](https://github.com/affaan-m/ECC/blob/main/.agents/skills/security-review/SKILL.md): privilege checks, nonce/CSRF boundaries, validation, archive extraction, SSRF, key handling and data leakage.
- [verification-loop](https://github.com/affaan-m/ECC/blob/main/.agents/skills/verification-loop/SKILL.md): test suite + static checks + independent diff review before a change can be considered ready.
- [api-design](https://github.com/affaan-m/ECC/blob/main/.agents/skills/api-design/SKILL.md): stable resource contracts, status codes, idempotency, pagination and error semantics.
- [e2e-testing](https://github.com/affaan-m/ECC/blob/main/.agents/skills/e2e-testing/SKILL.md): critical user journeys, deterministic fixtures, failure evidence and browser coverage.

## PHP/WordPress adaptations

1. **Analyze impact:** identify all relevant hooks, WordPress capabilities, cron scheduling, AJAX/REST entry points, backup formats, database prefixes and server configurations. Check that backups remain recoverable if the import or upgrade crashes mid-step.
2. **Test first:** add focused PHP regression tests and fixtures for happy path, invalid inputs, corruption and interrupted transfers. Use WordPress/MySQL integration tests for exports/imports, HTTP permissions and restores. Use browser E2E tests for interactive migration flows; ECC's JavaScript examples are illustrative, not commands to copy into a PHP plugin.
3. **Implement in small commits:** separate security fixes, migrations, UI, translations and external adapters; retain compatibility with existing `.wpress` archives.
4. **Security review:** validate `current_user_can`, nonce and scope, REST `permission_callback`, prepared SQL, file paths and symlinks, HTTP access to temporary files, TLS and SSRF. Keep keys and backups private.
5. **Verification:** PHP syntax check for all PHP files, translation consistency, smoke regressions, WP-CLI end-to-end export/restore and GitHub Actions PHP matrix. Measure code coverage rather than asserting it; add coverage tooling before claiming a numeric percentage.
6. **Release gate:** review changed files against intended behavior and failure modes, document risks and rollback, require green checks. Do not merge draft code into `main` or advertise untested features as production-ready.

This document is a project-specific checklist, not a claim that ECC plugins are installed or its proprietary workflows are running.
