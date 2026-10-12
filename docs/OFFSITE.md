# Offsite storage adapters

The base plugin provides a bounded streaming interface with three current backends: `local`, `webdav`, and `s3`. S3-compatible endpoints can include Amazon S3, Cloudflare R2, and Backblaze B2, subject to provider-specific features/credentials. Google Drive, Dropbox, and OneDrive OAuth connectors are **not implemented yet**.

## Configuration

Set values in `wp-config.php`, before WordPress loads plugins. Never commit credentials.

**Private filesystem (also suitable as a mounted secure network volume):**

```php
define( 'TRASLOCO_REMOTE_DRIVER', 'local' );
define( 'TRASLOCO_REMOTE_DIRECTORY', '/srv/private/offsite-mirror' );
```

**S3 / R2 / B2:**

```php
define( 'TRASLOCO_REMOTE_DRIVER', 's3' );
define( 'TRASLOCO_S3_BUCKET', 'my-backup-bucket' );
define( 'TRASLOCO_S3_REGION', 'us-east-1' );
define( 'TRASLOCO_S3_KEY', getenv( 'TRASLOCO_S3_KEY' ) );
define( 'TRASLOCO_S3_SECRET', getenv( 'TRASLOCO_S3_SECRET' ) );
// For R2/B2, set your trusted HTTPS S3 endpoint:
// define( 'TRASLOCO_S3_ENDPOINT', 'https://<account-id>.r2.cloudflarestorage.com' );
```

The S3 driver requires the AWS SDK for PHP v3. Install with `composer install --no-dev --optimize-autoloader` when preparing a plugin package. A raw source checkout without dependencies gracefully reports the missing SDK. Large uploads use the SDK ObjectUploader (multipart). Endpoint support differs by provider.

**WebDAV over HTTPS:**

```php
define( 'TRASLOCO_REMOTE_DRIVER', 'webdav' );
define( 'TRASLOCO_WEBDAV_URL', 'https://webdav.example.com/trasloco/' );
define( 'TRASLOCO_WEBDAV_USER', getenv( 'TRASLOCO_WEBDAV_USER' ) );
define( 'TRASLOCO_WEBDAV_PASSWORD', getenv( 'TRASLOCO_WEBDAV_PASSWORD' ) );
```

The WebDAV destination directory must already exist. WordPress validates the configured HTTPS URL to reduce SSRF risk; TLS certificate verification is enforced and redirects are disabled.

## Transfer commands

```bash
wp trasloco encrypt mysite.wpress
wp trasloco offsite upload mysite.wpress.enc
# On a new server using the same storage configuration:
wp trasloco offsite download mysite.wpress.enc
wp trasloco decrypt mysite.wpress.enc
```

Plain backup uploads require explicit `--allow-unencrypted` and transfer the integrity manifest as well. The target refuses to overwrite existing local archives. For encrypted files, integrity is checked during authenticated decryption.

## Not yet shipped

- Drive/Dropbox/OneDrive OAuth (token refresh and revocation)
- Remote upload status/progress, cancellation and durable multipart resume
- Built-in cloud picker and credentials form in the WordPress UI
- Remote retention/GC and immutable object versioning
- End-to-end live integration tests against S3, R2, B2 and WebDAV providers

Do not assume remote offsite success without exercising a full download and isolated restoration.
