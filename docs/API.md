# Trasloco API v1

All endpoints are guarded by `current_user_can('manage_options')`. Authenticate remote clients using HTTPS and WordPress Application Passwords; do not put credentials in query strings. There are no anonymous endpoints.

- `GET /wp-json/trasloco/v1/health`: PHP/WordPress version, writable backup path, active job state and advisory WooCommerce HPOS signal
- `GET /wp-json/trasloco/v1/backups`: archive names, sizes, timestamps and manifest status (no costly hashing)
- `POST /wp-json/trasloco/v1/backups/{archive}.wpress/verify`: recompute SHA-256, returning `verified`, `legacy`, `invalid` or `corrupt`
- `GET /wp-json/trasloco/v1/jobs`: active job and most recent result
- `POST /wp-json/trasloco/v1/jobs`: begin a background full backup, HTTP 202; HTTP 409 when another job is active

On WordPress 6.9+, the read-only ability `trasloco/site-readiness` is registered (not exposed anonymously or through the Abilities REST transport). Earlier WordPress versions continue to work.

### Security notes

Only site administrators may initiate operations. Backups are never downloadable using these REST endpoints. Remote callers must employ TLS, scoped application passwords and revocation; account passwords must never be embedded in source. Backups may take time to finish under WP-Cron; use system cron for predictable delivery. API permissions alone do not establish availability or safety for public multi-tenant agency orchestration.
