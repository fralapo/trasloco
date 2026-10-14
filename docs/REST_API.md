# Trasloco REST API

The API uses the `trasloco/v1` namespace. Authenticate with a WordPress cookie and REST nonce or a revocable Application Password. Every route requires the `manage_options` capability. Send JSON for POST requests and use TLS when calling a remote WordPress site.

`POST /wp-json/trasloco/v1/jobs/backup` queues a local backup and returns HTTP 202 with a durable `job_id`. The optional `retention` field is an integer from 1 to 100; `upload_offsite` is a boolean and defaults to false. An optional `idempotency_key` (12–64 letters, digits, colon, underscore or hyphen) makes client retries safe. Reusing that key for a different payload returns a conflict. The worker performs the same integrity verification and retention handling as scheduled backups.

`GET /wp-json/trasloco/v1/jobs` and `GET /wp-json/trasloco/v1/jobs/<id>` expose bounded job state without payloads, credentials or filesystem paths. `GET /wp-json/trasloco/v1/audit?per_page=100` returns up to 100 recent REST mutation records containing only the action, WordPress actor ID, job ID and timestamp. The audit table retains at most 1,000 recent records.

The REST API does not start a restore. Restoring replaces database and file content, so it remains an explicit WP-CLI or administrator workflow with preflight and a verified recovery point.
