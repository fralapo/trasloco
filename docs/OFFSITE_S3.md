# S3-compatible offsite storage

Trasloco includes an S3-compatible storage adapter for AWS S3 and services that implement its object API. It supports private objects, create-only uploads, multipart uploads for large archives, exact byte-range downloads, bounded inventory and the standard AWS credential provider chain. Multipart upload IDs are checkpointed on durable transfer jobs, and workers recover uploaded-part lists from the provider after interruption. Queued multipart uploads and scheduled encrypted S3 delivery are therefore available alongside WebDAV delivery.

## Requirements and installation

The optional AWS SDK runtime requires PHP 8.1 or newer. Trasloco's core remains compatible with PHP 7.4; install this runtime only on a site using PHP 8.1+ and S3 storage. From the Trasloco plugin directory, install the committed lock file's production dependencies:

```sh
COMPOSER=composer.s3.json composer install --no-dev --classmap-authoritative
```

This creates `vendor-s3/`, which must be present in the deployed plugin directory. The optional dependencies are ignored by Git. To build a plugin ZIP with the complete S3 runtime, run `tools/build-release.sh --with-s3` on a PHP 8.1+ build environment. The script includes and verifies `vendor-s3/autoload.php`. Do not install Composer packages in a web request.

## Configuration

Put these constants in the site's private `wp-config.php` before WordPress loads:

```php
define( 'TRASLOCO_REMOTE_DRIVER', 's3' );
define( 'TRASLOCO_S3_BUCKET', 'private-backups-example' );
define( 'TRASLOCO_S3_REGION', 'eu-west-1' );
define( 'TRASLOCO_S3_PREFIX', 'wordpress/site-a' ); // Optional.
```

Prefer the AWS SDK's standard server credential chain, such as an instance or workload role. For compatible services that issue access keys, configure both constants:

```php
define( 'TRASLOCO_S3_ACCESS_KEY', 'access-key' );
define( 'TRASLOCO_S3_SECRET_KEY', 'secret-key' );
// Optional for temporary credentials:
define( 'TRASLOCO_S3_SESSION_TOKEN', 'session-token' );
```

The older `TRASLOCO_S3_KEY` and `TRASLOCO_S3_SECRET` names are accepted for existing private configuration. For an S3-compatible service, set its HTTPS API endpoint and enable path-style addressing only when that provider requires it:

```php
define( 'TRASLOCO_S3_ENDPOINT', 'https://objects.example.net' );
define( 'TRASLOCO_S3_PATH_STYLE', true );
```

The adapter verifies TLS certificates, uses Signature Version 4, rejects HTTP endpoints and URL credentials, disables redirects and proxy use, and never puts credentials in object keys or backup manifests. Use a dedicated private bucket/prefix and a least-privilege policy that allows listing that prefix and getting, creating, and deleting its objects. Configure a provider lifecycle rule to remove abandoned multipart uploads.

## Transfer behavior and limits

Normal WP-CLI `offsite upload`, `offsite download`, `offsite status`, `queue-upload`, and `queue-download` can use S3. Scheduled backups can also request encrypted offsite delivery. Durable job checkpoints retain only the bucket, object key, multipart upload ID, part size and verified local archive digest; the upload ID is omitted from public job status. On replay, the AWS SDK lists remote parts and uploads only the missing ones. Transfers verify the local integrity manifest and read the completed remote object back before reporting success. Writes use conditional create-only requests for both single-part upload and multipart completion; if the provider does not implement those conditions, uploads fail closed. Existing objects are never replaced by Trasloco.

Abandoned parts from exhausted or deleted jobs still need the provider's multipart lifecycle cleanup. Local SDK fixtures interrupt an upload after a completed part and resume it from `ListParts`; HTTP wire fixtures exercise signed S3 requests, create-only writes, downloads, ranges, inventory, deletion and error handling. A real AWS/S3-compatible provider, permission policies, host restart replay and provider durability still need separate validation.

Run the local SDK fixtures with:

```sh
php tests/s3-storage-smoke.php
tests/s3-wire-smoke.sh
```

The wire fixture starts a loopback-only PHP server and exercises the real AWS SDK request signer and XML/HTTP response parser. It does not contact AWS or certify a third-party S3 service.
