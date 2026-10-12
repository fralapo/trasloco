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
