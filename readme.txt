=== Trasloco ===
Tags: backup, migration, migrate, export, import
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Free, open source plugin to back up and migrate WordPress sites, with no size limit.

== Description ==

Trasloco exports a whole WordPress site (database, media, themes and plugins) to a single .wpress file and imports it on any other WordPress install.

* No size limit.
* Chunked, base64-encoded upload. It gets past hosting firewalls that block binary uploads, and a chunk that is retried is never written twice.
* The site address changes by itself on import, serialized data included. Find and replace for any other text.
* Export, Import, Backups and Guide pages: every option is explained in plain words, and a progress bar shows each step.
* Interface in English, Italian, Spanish, French and German. All translations are in languages/translations.php.
* WP-CLI: wp trasloco backup, wp trasloco restore <file>.
* No paid tiers, no accounts, no calls home.

== Installation ==

1. Upload the trasloco folder to wp-content/plugins, or use Plugins > Add New > Upload Plugin with trasloco.zip.
2. Activate Trasloco.

== Usage ==

1. On the source site: Trasloco > Export > Create export file, then download the .wpress file.
2. On the destination site: Trasloco > Import, drop the file and confirm with Replace site.
3. Log in with the source site's username and password, then save the permalink settings twice.

The new address is set by the import: there is nothing to type. Trasloco > Guide explains every option.

== Changelog ==

= 1.4.0 =
* Administrators can queue authenticated backup encryption and decryption from the Backups page. Bounded jobs resume after interruption, preserve TRLENC01 decryption compatibility and publish output only after authentication and archive inspection.
* Secretstream checkpoints are sealed with the WordPress authentication salt; passphrases are not saved in job payloads or sent in forms. Final archive inspection and checksum generation still scan the completed file in one worker request.
* The complete local WordPress integration passes on PHP 7.4, 8.2, 8.4 and 8.5. Security smoke tests pass on all four versions. Real-host proxy, WAF and timeout testing remains open.

= 1.3.0 =
* Interrupted local backup jobs resume from flushed export checkpoints, discard uncommitted trailing bytes and avoid duplicate archives during finalization.
* Administrators can queue backups and inspect a bounded audit log through the REST API; backup and offsite alerts support retried email and signed HTTPS webhooks.
* Scheduled encrypted offsite delivery supports local WebDAV and S3 adapters, resumable transfer jobs and provider health checks. Real provider certification remains open.
* File recovery supports reviewed path exclusions; progress windows reflow on narrow screens and honor reduced-motion preferences.
* Local PHP 7.4/8.2/8.4 integration passes with MariaDB 13. Cross-host/NFS, real-host HTTP, real provider, multisite and independent security certification remain open.

= 1.2.0 =
* Scheduled backups with verified retention, resumable SHA-256 verification and authenticated backup downloads.
* Optional authenticated encryption and independent rescue commands for encrypted archives.
* Pre-import database recovery snapshots and two-phase offline recovery for changed or missing files, with preserved originals and safe resume after interruption.
* Recovery apply is serialized per workspace; unrelated files are not deleted. Full-site rollback is not globally atomic or automatic.
* Expanded security checks, administrator preflight and archive inventory, plus PHP 7.4/8.2/8.4 integration coverage with MySQL 8.0 and MariaDB 11.4.
* Real-host HTTP/WAF certification, multisite and WooCommerce validation, and independent security review remain pending.

= 1.1.3 =
* Work folders left by an import or export that stopped halfway (for example a large upload cut off) are removed after a day, so they no longer take up disk space on the server.
* Removed a PHP 8.1 deprecation notice when the site address has no path.

= 1.1.2 =
* Fixed a fatal error on activation when All-in-One WP Migration is active on the same site: the bundled template library now has its own name.

= 1.1.1 =
* Upload no longer stops at 0% behind hosting firewalls that refuse requests over about 1 MB: a refused chunk is sent again at half the size.
* The chunk size now leaves room for the base64 encoding, so hosts with a small post_max_size work too.
* The import no longer says the site was imported when the database was not: a missing database stops the import with a clear message, and failed database commands are shown as a warning at the end.

= 1.1.0 =
* Every page explains what it does in plain words; every export option has a one-line explanation.
* New Guide page with a glossary, a step-by-step move, every option and what to do when something goes wrong.
* Export page no longer asks for the new site address: the import changes it by itself.
* Import page lists what to check before starting; progress and error messages say what is happening and what to do.
* Security: backup file names now end with a 12-character random code instead of a 3-digit number.
* Accessibility: real buttons, named dialogs, announced progress, focus returns where it was.

= 1.0.0 =
* First release.

== License ==

AGPL-3.0-or-later (see LICENSE). It includes GPLv3 code, Copyright (C) 2014-2018 ServMask Inc. (see NOTICE and LICENSE-GPL-3.0).
