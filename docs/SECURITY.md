# Security and safe deployment

Trasloco handles full WordPress databases, users, media, and potentially secrets. Do not publish generated archives or logs to the web.

## Authorization
- Administrative pages require `manage_options`.
- Asynchronous operations use signed, four-hour, operation-scoped tokens. The permanent site secret is never sent to the browser.
- Backups download through an authenticated `admin-post.php` handler with an action-specific WordPress nonce.
- A restore can replace the current session, so the signed token can authorize continuation after users are migrated. Treat a leaked token as sensitive until it expires.

## Web server blocking
New installations create Apache and IIS rules that deny direct HTTP requests for `.wpress`. Existing Apache rules are upgraded. The temporary working folder blocks HTTP access on Apache and IIS.

**Nginx ignores .htaccess and web.config.** Add a denial rule in your vhost *before* using the plugin on production. Example (adapt paths to your setup):

```nginx
location ~* ^/wp-content/trasloco-backups/ { return 403; }
location ~* ^/wp-content/plugins/trasloco/storage/ { return 403; }
```

If WordPress is installed in a subdirectory, update the prefix. If the folder was renamed by a ZIP installation, update the plugin path as well. Prefer keeping backups **outside the document root** when your hosting permits it. On IIS, existing web.config files from older releases may need manual replacement with the new template.

Verify from a logged-out/incognito browser that **all direct archive URLs return 403 or 404**, not 200. Random filenames and directory-listing blocks are not a substitute for download authorization.

## Before restoring a site
1. Make an independent off-site backup of the destination (files and database).
2. Verify available disk space and PHP/MySQL compatibility.
3. Test a full restore on a staging clone.
4. Run the migration and confirm the old account credentials work.
5. Verify pages, images, plugins, forms, permalinks and WooCommerce data where relevant.
6. Remove obsolete archives from the server.

## Limitations
- Stopping a failed SQL import cannot automatically reverse MySQL DDL such as DROP TABLE.
- SQL errors now stop the restore, but a previously modified destination must still be recovered from an independent backup.
- Current CLI smoke tests do not prove compatibility on real hosting, Nginx, multisite, or cross-version database imports.
- Keep migrating production sites in a maintenance window, especially stores with ongoing orders.

Please report security problems privately to the repository owner before publishing exploit details.

## Optional private backup directory

In `wp-config.php`, before WordPress loads the plugin, define a location outside your HTTP document root:

```php
define( 'TRASLOCO_BACKUPS_DIR', '/srv/private/wordpress-backups' );
```

Create the directory with permissions that allow the PHP/CLI runtime to write it. This is **opt-in**: existing archives are not moved automatically. If changing the path, move your existing backups separately and verify permissions. The WordPress Backups screen and WP-CLI use the configured path.


## Decrypted temporary data

Archives and their temporary files must **never** be available over HTTP, even for a short time. Updated Apache and IIS rules deny the **entire** backup directory. For Apache, a legacy folder's rules are appended with a new directory-deny directive; for IIS, update an older `web.config` manually, since the plugin does not overwrite existing custom configuration. Nginx requires the vhost denial rule above, including temporary filenames.

Run a logged-out HTTP check for `/wp-content/trasloco-backups/index.php` and for a temporary test file with an arbitrary extension; both must return 403/404. Do not decrypt to a publicly accessible directory unless its HTTP access has been disabled.

## Extraction symlink defense

The extraction routines reject on-disk symbolic-link path components before writing. Existing symlink-based WordPress deployments may need a documented, physical extraction destination; the importer fails closed rather than follow links. Regression tests cover full and selected archive-file extraction through a symlink pointing outside the destination. This is a filesystem-level check, not a replacement for server isolation: malicious processes with concurrent write permissions could still race between validation and fopen. Use private storage and trusted hosting.

## Integrity gate before replacement

Before replacing a site from a **local** backup, Trasloco checks its SHA-256 manifest if one is present and refuses a mismatch before creating the recovery snapshot or touching destination files/database. Legacy backups and uploaded files without sidecar manifests remain supported; they undergo structural validation but do **not** have cryptographic origin authentication. SHA-256 sidecars are not signatures and can be forged by anyone who can modify both files.

## Worker backup concurrency

Server-side scheduled backups and automatic recovery snapshots share an exclusive non-blocking `flock` mutex in the backups directory. When another server-side export holds the lock, the new job fails before starting its export rather than risk overlapping writes. Filesystem locks require a local/shared filesystem that supports `flock`; multiple independent servers and older interactive AJAX export flows are **not yet covered** by this lock. A future durable import/export orchestration layer must coordinate those paths too.

When Nginx is detected and the backups directory appears to reside under the site's web document root, administrators now receive a translated warning. This is a best-effort heuristic; proxies, container mounts or unusual document roots can bypass detection. A real logged-out HTTP test remains mandatory.

## Full backups and linked WordPress content

Full backup export intentionally **stops** if any symbolic link is present under `wp-content`. Symlinked plugins, themes or uploads can point outside the WordPress content root, and silently skipping them would produce a misleadingly incomplete backup. The exporter checks for links before applying its exclusion filters; regular archive exports do not dereference those links. Remove links or put physical copies of their content inside `wp-content`, rerun the backup and verify an independent restore. This protection does not prevent all filesystem races against a malicious process with write access.
