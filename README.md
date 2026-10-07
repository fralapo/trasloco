<a id="readme-top"></a>

<p align="center"><img src="lib/view/assets/img/logo.svg" width="128" height="128" alt="Trasloco logo: a cardboard moving box sealed with packing tape"></p>

# Trasloco: free WordPress backup and migration plugin

**Trasloco is a free, open source WordPress plugin that backs up and migrates a whole WordPress site.** It exports the database, media library, themes and plugins into a single `.wpress` file, then imports that file on any other WordPress install, with no file size limit, no paid add-ons and no account. It is tested with a real 1.64 GB site.

[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)
![Version](https://img.shields.io/badge/version-1.1.2-informational.svg)
![Languages](https://img.shields.io/badge/languages-en%20it%20es%20fr%20de-success.svg)
![Tested on PHP 8.2](https://img.shields.io/badge/tested_on-PHP_8.2-777bb4.svg)

I wrote it to move a 1.6 GB site to shared hosting without paying for a "remove the size limit" add-on, and without an admin screen full of upsell buttons. Everything is in the plugin. There are no paid tiers, no accounts and no calls home. ("Trasloco" is Italian for "house move".)

- [Features](#features)
- [Install](#install)
- [Usage](#usage)
- [Languages](#languages)
- [FAQ](#faq)
- [Known limits](#known-limits)
- [The .wpress format](#the-wpress-format)
- [Contributing](#contributing)
- [License](#license)

## Features

- **No size limit.** `TRASLOCO_MAX_FILE_SIZE` is 0, which means unlimited.
- **Chunked upload that survives firewalls and retries.** The browser sends the file in chunks of up to 2 MB, base64-encoded, each one with its byte offset. Some hosting firewalls reject binary uploads that happen to contain PHP code; base64 text gets through. Others reject any request over about 1 MB: when a chunk is refused, the next try uses a chunk half the size. If a chunk is sent twice after a network error, it overwrites the same bytes instead of being appended again, so the archive stays intact.
- **Reads old and new `.wpress` archives.** Newer writers put an 8-character CRC at the end of the path field and close the archive with an end block that has an empty name. Trasloco accepts both that layout and the older all-zero end block.
- **The site address changes by itself.** On import, the old address is replaced with the new one in links, images and settings, serialized data included. Email addresses on the old domain change too, unless you turn that off.
- **Find and replace for other text.** For anything else that must change on the new site, such as an old phone number or a second domain.
- **Four plain pages:** Export, Import, Backups and Guide. Every option has a one-line explanation under it, the Import page lists what to do before you start, and a progress bar shows each step. The Guide page explains every function, with a glossary for people who do not know how WordPress works inside.
- **Five languages:** English, Italian, Spanish, French and German, all in one file. See [Languages](#languages).
- **PHP 8.2 clean.** The iterators carry `#[\ReturnTypeWillChange]`, so the log is free of deprecation notices.
- **WP-CLI:** `wp trasloco backup` and `wp trasloco restore <file>`.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Install

From a zip: download [`trasloco.zip` from the latest release](https://github.com/fralapo/trasloco/releases/latest/download/trasloco.zip), then in WordPress go to **Plugins → Add New → Upload Plugin**. (**Code → Download ZIP** also works, but the folder is then called `trasloco-main`.)

From a terminal, inside the site's plugin folder:

```bash
cd wp-content/plugins
git clone https://github.com/fralapo/trasloco.git
```

Then activate **Trasloco** in the plugin list.

## Usage

**On the source site**

1. Go to **Trasloco → Export**.
2. Click **Create export file**, wait for it to finish and download the `.wpress` file.

You do not need to type the new address anywhere: the import changes it.

**On the destination site**

1. Go to **Trasloco → Import** and drop the file on the page (or choose it from your computer).
2. When asked, click **Replace site**. The import overwrites the database, media, themes and plugins and cannot be undone, so back up first.
3. When it finishes, save the permalink settings twice and log in with the source site's username and password. The users table was replaced too.

The uploaded archive stays under **Trasloco → Backups**, where you can download, restore or delete it.

## Languages

The interface follows the language set in the WordPress user profile (**Users → Profile → Language**). Italian, Spanish, French and German are included; any other language falls back to English.

All translations live in one file, [`languages/translations.php`](languages/translations.php). Each entry is the English text exactly as it appears in the code, with one translation per language:

```php
'Replace site' => array(
	'it' => 'Sostituisci il sito',
	'es' => 'Reemplazar el sitio',
	'fr' => 'Remplacer le site',
	'de' => 'Website ersetzen',
),
```

PHP templates use the usual WordPress functions (`__()`, `esc_html_e()`), and a `gettext` filter looks each string up in that file. The JavaScript has no text of its own: it receives the translated strings through `wp_localize_script`, so it reads from the same file. There are no `.po` or `.mo` files to compile.

To add a language, add its key (the first two letters of the locale, for example `pt` for `pt_BR`) to every entry and to `$langs` in `tools/check-translations.php`, then run:

```bash
php tools/check-translations.php
```

The script collects every plugin string from the code. It fails if a string is missing, if a language is missing, or if a translation lost a placeholder (`%s`, `%d`) or an HTML tag.

## FAQ

### How do I migrate a WordPress site with Trasloco?
Install Trasloco on both sites. On the old site, open **Trasloco → Export** and click **Create export file**, then download the `.wpress` file. On the new site, open **Trasloco → Import**, drop the file on the page and click **Replace site**. Finally, save the permalink settings twice.

### Is there a file size limit?
No, on 64-bit PHP, which almost every host uses. (32-bit PHP cannot handle files over 2 GB, and Trasloco tells you so.) Uploads travel in 2 MB chunks, so the PHP `upload_max_filesize` setting does not cap the archive size. The largest site tested so far is 1.64 GB (18,240 files, 108 database tables).

### Is Trasloco free for commercial use?
Yes. Trasloco is free software under the AGPL-3.0 license: you can use it on client sites and build paid products or services on it. If you distribute a modified version, or offer one over a network, you must publish its source code under the same license.

### Does Trasloco change the site URL during a migration?
Yes, on its own. When the file is imported, the old address is replaced with the address of the new site everywhere in the database, including serialized data, which a plain SQL search and replace would corrupt. Email addresses on the old domain are changed as well (info@old.com becomes info@new.com); tick "Keep email addresses unchanged" on the Export page to avoid that, for example when you copy a live site to a staging address. "Find and replace text" on the Export page is only for other text.

### What else does the import change on its own?
At the end it switches on the theme and the plugins that were active on the old site. If the new site has no HTTPS, it switches off plugins that force HTTPS. It also switches off plugins that hide or rename the login page, so that you can log in at `/wp-login.php`. The users are those of the old site, so you log in with the old username and password.

### Who can download the backup files?
They sit in `wp-content/trasloco-backups`. Visitors cannot list the folder, and every file name ends with a random 12-character code. Anyone who has the exact link can still download a file, and it contains the whole site, user accounts included, so do not share the links and delete the backups you no longer need.

### Can I restore a site from the command line?
Yes, with WP-CLI: `wp trasloco backup` creates a backup (it takes the same options as the Export page, such as `--exclude-media` or `--replace "old" "new"`), `wp trasloco backup --list` lists the backups, and `wp trasloco restore <file>` restores one from the `wp-content/trasloco-backups` folder.

### What does a Trasloco backup contain?
One `.wpress` file holds the database dump, the `wp-content` uploads, themes, plugins and must-use plugins. Spam comments, post revisions, media, themes, plugins, cache or the database can each be left out on the Export page.

### Which WordPress and PHP versions does Trasloco support?
Trasloco needs WordPress 5.5 or later. It is tested on WordPress 7.1 with PHP 8.2. PHP 7.4 is supported by the code but has not been tested yet.

### Which languages does the Trasloco admin interface support?
English, Italian, Spanish, French and German. The language follows the WordPress user profile, and any other language falls back to English. See [Languages](#languages) to add one.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## Known limits

As of version 1.1.0 (October 2026):

- **Not yet tried on a real host behind a firewall.** So far it has been tested on local WordPress installs with PHP 8.2, including an import of a real 1.64 GB site (12 pages, 73 media files, 19 plugins). The base64 upload was built for hosting firewalls, but it has not been seen working behind one yet. PHP 7.4 has not been tested either.
- **Single sites only.** Importing a multisite network is not supported.
- **PHP's built-in web server is slow with big sites.** This is not a plugin problem, but if you use `php -S` for testing, each import step can take tens of seconds.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## The .wpress format

This section is for anyone who wants to write their own reader. A `.wpress` file is a sequence of uncompressed entries. Each entry is a 4377-byte header followed by the file contents:

| Field | Bytes | Content |
|---|---|---|
| name | 255 | file name |
| size | 14 | content length in bytes, as decimal text |
| mtime | 12 | Unix timestamp |
| path | 4096 | relative folder, `.` for the root |

In newer archives the path field is NUL-padded and its last 8 characters are a CRC of the header. The archive ends with a 4377-byte block. In the older layout it is all zeros; in the newer one it has an empty name and carries a size and a CRC. The reader lives in `lib/vendor/core/archiver/class-trasloco-extractor.php` (`get_data_from_block()`) and `class-trasloco-archiver.php` (`is_eof_block()`).

Inside the archive, `database.sql` writes table names with the placeholder prefix `SERVMASK_PREFIX_`, which import replaces with the destination prefix. Keep that string as it is, or existing archives will stop importing.

## Contributing

Issues and pull requests are welcome. Reports from real hosting setups are especially useful, above all from hosts with a firewall.

If you build on Trasloco, including in a paid product or service, please send your improvements back here as pull requests so everyone gets them. The license already requires you to publish your modified source (see below); a pull request is the easiest way to do it.

If you change a user-facing string in the code, update the same entry in `languages/translations.php` and run `php tools/check-translations.php`.

<p align="right">(<a href="#readme-top">back to top</a>)</p>

## License

Trasloco is released under the **GNU Affero General Public License v3.0** ([LICENSE](LICENSE)). You can use it for free, change it and sell products or services built on it. If you distribute a modified version, or let people use one over a network, you must give them its complete source code under the same license.

Part of the code comes from a GPLv3 project, Copyright © 2014-2018 ServMask Inc. The files that carry that copyright header remain under the GNU GPL v3 ([LICENSE-GPL-3.0](LICENSE-GPL-3.0)) and were modified for Trasloco in 2026. Section 13 of the GPL v3 allows them to be combined with AGPL code, and the AGPL network clause applies to the combined work. See [NOTICE](NOTICE) for details.
