<?php
/**
 * Plugin Name: Trasloco
 * Description: Free, open source plugin to back up and migrate WordPress sites. Export the whole site (database, media, themes and plugins) to a single file and import it anywhere, with no size limit.
 * Author: Trasloco contributors
 * Version: 1.1.2
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: trasloco
 * Domain Path: /languages
 * Network: True
 *
 * Copyright (C) 2026 Trasloco contributors
 * Copyright (C) 2014-2018 ServMask Inc.
 *
 * Modified by the Trasloco contributors in 2026. The combined work is
 * distributed under the AGPL-3.0-or-later; this file keeps the original
 * GPL-3.0-or-later notice below. See NOTICE.
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

/**
 * Trasloco: every translation lives in languages/translations.php
 * (one entry per English source string, one key per language).
 * PHP views read it through gettext, the JavaScript through wp_localize_script.
 *
 * @param  string $translation Text translated so far
 * @param  string $text        English source text
 * @return string
 */
function trasloco_translate( $translation, $text = null ) {
	static $strings = null;

	if ( null === $strings ) {
		$strings = include dirname( __FILE__ ) . '/languages/translations.php';
	}

	if ( null === $text ) {
		$text = $translation;
	}

	if ( ! isset( $strings[ $text ] ) ) {
		return $translation;
	}

	$lang = substr( determine_locale(), 0, 2 );
	if ( isset( $strings[ $text ][ $lang ] ) ) {
		return $strings[ $text ][ $lang ];
	}

	return $translation;
}

add_filter( 'gettext_trasloco', 'trasloco_translate', 10, 2 );

// Check SSL Mode
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && ( $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) ) {
	$_SERVER['HTTPS'] = 'on';
}

// Plugin Basename
define( 'TRASLOCO_PLUGIN_BASENAME', basename( dirname( __FILE__ ) ) . '/' . basename( __FILE__ ) );

// Plugin Path
define( 'TRASLOCO_PATH', dirname( __FILE__ ) );

// Plugin Url
define( 'TRASLOCO_URL', plugins_url( '', TRASLOCO_PLUGIN_BASENAME ) );

// Plugin Storage Url
define( 'TRASLOCO_STORAGE_URL', plugins_url( 'storage', TRASLOCO_PLUGIN_BASENAME ) );

// Plugin Backups Url
define( 'TRASLOCO_BACKUPS_URL', content_url( 'trasloco-backups', TRASLOCO_PLUGIN_BASENAME ) );

// Themes Absolute Path
define( 'TRASLOCO_THEMES_PATH', get_theme_root() );

// Include constants
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'constants.php';

// Include deprecated
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'deprecated.php';

// Include functions
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'functions.php';

// Include exceptions
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'exceptions.php';

// Include loader
require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'loader.php';

// =========================================================================
// = All app initialization is done in Trasloco_Main_Controller __constructor =
// =========================================================================
$main_controller = new Trasloco_Main_Controller();
