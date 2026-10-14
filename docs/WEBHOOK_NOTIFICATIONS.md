# Webhook notifications

In **Backups → Automatic backups**, enable email alerts, set an HTTPS webhook URL and provide a shared signing secret of 32–128 characters. Leave the secret field empty when editing the schedule to retain the current secret. The endpoint must be publicly routable; WordPress safe HTTP validation blocks private and loopback destinations. Redirects are disabled and the request timeout is five seconds.

Trasloco sends `backup_failed`, `offsite_attention` and `offsite_recovered` events as JSON. The body contains only the event name, an HMAC-derived site identifier, a Unix timestamp and the delivery attempt number. It does not include archive names, paths, credentials or error details.

The request includes `X-Trasloco-Event`, `X-Trasloco-Timestamp` and `X-Trasloco-Signature` headers. The signature value is `sha256=` followed by the lowercase hex HMAC-SHA-256 of `<timestamp>.<raw request body>`, using the configured secret. Receivers should reject stale timestamps and compare signatures with a constant-time comparison.

Email and webhook delivery are retried independently up to three times, after approximately 1, 5 and 15 minutes. Retries use WP-Cron, so reliable delivery depends on the site's cron configuration. A successful HTTP 2xx response acknowledges a webhook; other responses and transport errors are retried. This local test suite uses a mocked HTTP transport and does not certify any external endpoint.
