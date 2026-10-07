=== Trasloco ===
Tags: backup, migration, migrate, export, import
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Free, open source plugin to back up and migrate WordPress sites, with no size limit.

== Description ==

Trasloco exports a whole WordPress site (database, media, themes and plugins) to a single .wpress file and imports it on any other WordPress install.

* No size limit.
* Chunked, base64-encoded upload. It gets past hosting firewalls that block binary uploads, and a chunk that is retried is never written twice.
* Find and replace on export, safe for serialized data.
* Export, Import and Backups pages with a progress bar.
* Interface in English, Italian, Spanish, French and German. All translations are in languages/translations.php.
* WP-CLI: wp trasloco backup, wp trasloco restore <file>.
* No paid tiers, no accounts, no calls home.

== Installation ==

1. Upload the trasloco folder to wp-content/plugins, or use Plugins > Add New > Upload Plugin with trasloco.zip.
2. Activate Trasloco.

== Usage ==

1. On the source site: Trasloco > Export > Create export file, then download the .wpress file.
2. On the destination site: Trasloco > Import, drop the file and confirm with Replace site.
3. Save the permalink settings twice and log in with the source site's username and password.

== License ==

AGPL-3.0-or-later (see LICENSE). It includes GPLv3 code, Copyright (C) 2014-2018 ServMask Inc. (see NOTICE and LICENSE-GPL-3.0).
