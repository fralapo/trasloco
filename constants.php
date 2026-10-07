<?php
/**
 * Copyright (C) 2014-2018 ServMask Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ███████╗███████╗██████╗ ██╗   ██╗███╗   ███╗ █████╗ ███████╗██╗  ██╗
 * ██╔════╝██╔════╝██╔══██╗██║   ██║████╗ ████║██╔══██╗██╔════╝██║ ██╔╝
 * ███████╗█████╗  ██████╔╝██║   ██║██╔████╔██║███████║███████╗█████╔╝
 * ╚════██║██╔══╝  ██╔══██╗╚██╗ ██╔╝██║╚██╔╝██║██╔══██║╚════██║██╔═██╗
 * ███████║███████╗██║  ██║ ╚████╔╝ ██║ ╚═╝ ██║██║  ██║███████║██║  ██╗
 * ╚══════╝╚══════╝╚═╝  ╚═╝  ╚═══╝  ╚═╝     ╚═╝╚═╝  ╚═╝╚══════╝╚═╝  ╚═╝
 */

// ================
// = Plugin Debug =
// ================
define( 'TRASLOCO_DEBUG', false );

// ==================
// = Plugin Version =
// ==================
define( 'TRASLOCO_VERSION', '1.1.2' );

// ===============
// = Plugin Name =
// ===============
define( 'TRASLOCO_PLUGIN_NAME', 'trasloco' );

// ============================
// = Directory index.php File =
// ============================
define( 'TRASLOCO_DIRECTORY_INDEX', 'index.php' );

// ================
// = Storage Path =
// ================
define( 'TRASLOCO_STORAGE_PATH', TRASLOCO_PATH . DIRECTORY_SEPARATOR . 'storage' );

// ==================
// = Error Log Path =
// ==================
define( 'TRASLOCO_ERROR_FILE', TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . 'error.log' );

// ===============
// = Status Path =
// ===============
define( 'TRASLOCO_STATUS_FILE', TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . 'status.js' );

// ============
// = Lib Path =
// ============
define( 'TRASLOCO_LIB_PATH', TRASLOCO_PATH . DIRECTORY_SEPARATOR . 'lib' );

// ===================
// = Controller Path =
// ===================
define( 'TRASLOCO_CONTROLLER_PATH', TRASLOCO_LIB_PATH . DIRECTORY_SEPARATOR . 'controller' );

// ==============
// = Model Path =
// ==============
define( 'TRASLOCO_MODEL_PATH', TRASLOCO_LIB_PATH . DIRECTORY_SEPARATOR . 'model' );

// ===============
// = Export Path =
// ===============
define( 'TRASLOCO_EXPORT_PATH', TRASLOCO_MODEL_PATH . DIRECTORY_SEPARATOR . 'export' );

// ===============
// = Import Path =
// ===============
define( 'TRASLOCO_IMPORT_PATH', TRASLOCO_MODEL_PATH . DIRECTORY_SEPARATOR . 'import' );

// =============
// = View Path =
// =============
define( 'TRASLOCO_TEMPLATES_PATH', TRASLOCO_LIB_PATH . DIRECTORY_SEPARATOR . 'view' );

// ===================
// = Set Bandar Path =
// ===================
define( 'TRASLOCO_BANDAR_TEMPLATES_PATH', TRASLOCO_TEMPLATES_PATH );

// ===============
// = Vendor Path =
// ===============
define( 'TRASLOCO_VENDOR_PATH', TRASLOCO_LIB_PATH . DIRECTORY_SEPARATOR . 'vendor' );

// =========================
// = Table Prefix Placeholder =
// =========================
define( 'TRASLOCO_TABLE_PREFIX', 'SERVMASK_PREFIX_' );

// ========================
// = Archive Backups Name =
// ========================
define( 'TRASLOCO_BACKUPS_NAME', 'trasloco-backups' );

// =========================
// = Archive Database Name =
// =========================
define( 'TRASLOCO_DATABASE_NAME', 'database.sql' );

// ========================
// = Archive Package Name =
// ========================
define( 'TRASLOCO_PACKAGE_NAME', 'package.json' );

// ==========================
// = Archive Multisite Name =
// ==========================
define( 'TRASLOCO_MULTISITE_NAME', 'multisite.json' );

// ======================
// = Archive Blogs Name =
// ======================
define( 'TRASLOCO_BLOGS_NAME', 'blogs.json' );

// =========================
// = Archive Settings Name =
// =========================
define( 'TRASLOCO_SETTINGS_NAME', 'settings.json' );

// ==========================
// = Archive Multipart Name =
// ==========================
define( 'TRASLOCO_MULTIPART_NAME', 'multipart.list' );

// ========================
// = Archive Filemap Name =
// ========================
define( 'TRASLOCO_FILEMAP_NAME', 'filemap.list' );

// =================================
// = Archive Must-Use Plugins Name =
// =================================
define( 'TRASLOCO_MUPLUGINS_NAME', 'mu-plugins' );

// =============================
// = Endurance Page Cache Name =
// =============================
define( 'TRASLOCO_ENDURANCE_PAGE_CACHE_NAME', 'endurance-page-cache.php' );

// ===========================
// = Endurance PHP Edge Name =
// ===========================
define( 'TRASLOCO_ENDURANCE_PHP_EDGE_NAME', 'endurance-php-edge.php' );

// ================================
// = Endurance Browser Cache Name =
// ================================
define( 'TRASLOCO_ENDURANCE_BROWSER_CACHE_NAME', 'endurance-browser-cache.php' );

// =========================
// = GD System Plugin Name =
// =========================
define( 'TRASLOCO_GD_SYSTEM_PLUGIN_NAME', 'gd-system-plugin.php' );

// ===================
// = Export Log Name =
// ===================
define( 'TRASLOCO_EXPORT_NAME', 'export.log' );

// ===================
// = Import Log Name =
// ===================
define( 'TRASLOCO_IMPORT_NAME', 'import.log' );

// ==================
// = Error Log Name =
// ==================
define( 'TRASLOCO_ERROR_NAME', 'error.log' );

// ==============
// = Secret Key =
// ==============
define( 'TRASLOCO_SECRET_KEY', 'trasloco_secret_key' );

// =============
// = Auth User =
// =============
define( 'TRASLOCO_AUTH_USER', 'trasloco_auth_user' );

// =================
// = Auth Password =
// =================
define( 'TRASLOCO_AUTH_PASSWORD', 'trasloco_auth_password' );

// ============
// = Site URL =
// ============
define( 'TRASLOCO_SITE_URL', 'siteurl' );

// ============
// = Home URL =
// ============
define( 'TRASLOCO_HOME_URL', 'home' );

// ==================
// = Active Plugins =
// ==================
define( 'TRASLOCO_ACTIVE_PLUGINS', 'active_plugins' );

// ===========================
// = Active Sitewide Plugins =
// ===========================
define( 'TRASLOCO_ACTIVE_SITEWIDE_PLUGINS', 'active_sitewide_plugins' );

// ==========================
// = Jetpack Active Modules =
// ==========================
define( 'TRASLOCO_JETPACK_ACTIVE_MODULES', 'jetpack_active_modules' );

// ======================
// = MS Files Rewriting =
// ======================
define( 'TRASLOCO_MS_FILES_REWRITING', 'ms_files_rewriting' );

// ===================
// = Active Template =
// ===================
define( 'TRASLOCO_ACTIVE_TEMPLATE', 'template' );

// =====================
// = Active Stylesheet =
// =====================
define( 'TRASLOCO_ACTIVE_STYLESHEET', 'stylesheet' );

// ============
// = Cron Key =
// ============
define( 'TRASLOCO_CRON', 'cron' );

// ==============
// = Status Key =
// ==============
define( 'TRASLOCO_STATUS', 'trasloco_status' );

// ================
// = Messages Key =
// ================
define( 'TRASLOCO_MESSAGES', 'trasloco_messages' );

// =================
// = Max File Size =
// =================
define( 'TRASLOCO_MAX_FILE_SIZE', 0 ); // 0 = no limit

// ==================
// = Max Chunk Size =
// ==================
define( 'TRASLOCO_MAX_CHUNK_SIZE', 2 * 1024 * 1024 );

// =====================
// = Max Chunk Retries =
// =====================
define( 'TRASLOCO_MAX_CHUNK_RETRIES', 10 );

// ===========================
// = WP_CONTENT_DIR Constant =
// ===========================
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

// ================
// = Uploads Path =
// ================
define( 'TRASLOCO_UPLOADS_PATH', 'uploads' );

// ==============
// = Blogs Path =
// ==============
define( 'TRASLOCO_BLOGSDIR_PATH', 'blogs.dir' );

// ==============
// = Sites Path =
// ==============
define( 'TRASLOCO_SITES_PATH', TRASLOCO_UPLOADS_PATH . DIRECTORY_SEPARATOR . 'sites' );

// ================
// = Backups Path =
// ================
define( 'TRASLOCO_BACKUPS_PATH', WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'trasloco-backups' );

// ==========================
// = Storage index.php File =
// ==========================
define( 'TRASLOCO_STORAGE_INDEX', TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . 'index.php' );

// ==========================
// = Backups index.php File =
// ==========================
define( 'TRASLOCO_BACKUPS_INDEX', TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'index.php' );

// ==========================
// = Backups .htaccess File =
// ==========================
define( 'TRASLOCO_BACKUPS_HTACCESS', TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . '.htaccess' );

// ===========================
// = Backups web.config File =
// ===========================
define( 'TRASLOCO_BACKUPS_WEBCONFIG', TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . 'web.config' );

// ============================
// = WordPress .htaccess File =
// ============================
define( 'TRASLOCO_WORDPRESS_HTACCESS', ABSPATH . DIRECTORY_SEPARATOR . '.htaccess' );

// ================================
// = Plugin Base Dir        =
// ================================
if ( defined( 'TRASLOCO_PLUGIN_BASENAME' ) ) {
	define( 'TRASLOCO_PLUGIN_BASEDIR', dirname( TRASLOCO_PLUGIN_BASENAME ) );
} else {
	define( 'TRASLOCO_PLUGIN_BASEDIR', 'trasloco' );
}
