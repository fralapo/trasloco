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

// Include all the files that you want to load in here
require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'bandar' .
			DIRECTORY_SEPARATOR .
			'bandar' .
			DIRECTORY_SEPARATOR .
			'lib' .
			DIRECTORY_SEPARATOR .
			'Bandar.php';


if ( class_exists( 'WP_CLI' ) ) {
	require_once TRASLOCO_VENDOR_PATH .
				DIRECTORY_SEPARATOR .
				'core' .
				DIRECTORY_SEPARATOR .
				'command' .
				DIRECTORY_SEPARATOR .
				'class-trasloco-wp-cli-command.php';
}

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filesystem' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-directory.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filesystem' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-file.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filesystem' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-file-index.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filesystem' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-file-htaccess.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filesystem' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-file-webconfig.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'cron' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-cron.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'iterator' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-recursive-directory-iterator.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'iterator' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-recursive-iterator-iterator.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filter' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-recursive-extension-filter.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filter' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-recursive-exclude-filter.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'filter' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-recursive-newline-filter.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'archiver' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-archiver.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'archiver' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-compressor.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'archiver' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-extractor.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'database' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-database.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'database' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-database-mysql.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'database' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-database-mysqli.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'core' .
			DIRECTORY_SEPARATOR .
			'database' .
			DIRECTORY_SEPARATOR .
			'class-trasloco-database-utility.php';

require_once TRASLOCO_VENDOR_PATH .
			DIRECTORY_SEPARATOR .
			'math' .
			DIRECTORY_SEPARATOR .
			'BigInteger.php';

require_once TRASLOCO_CONTROLLER_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-main-controller.php';

require_once TRASLOCO_CONTROLLER_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-controller.php';

require_once TRASLOCO_CONTROLLER_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-controller.php';

require_once TRASLOCO_CONTROLLER_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-status-controller.php';

require_once TRASLOCO_CONTROLLER_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-backups-controller.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-init.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-archive.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-config.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-config-file.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-enumerate.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-content.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-database.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-database-file.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-download.php';

require_once TRASLOCO_EXPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-export-clean.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-upload.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-validate.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-blogs.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-confirm.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-enumerate.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-content.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-mu-plugins.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-database.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-done.php';

require_once TRASLOCO_IMPORT_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-import-clean.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-deprecated.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-backups.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-template.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-status.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-log.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-message.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-notification.php';

require_once TRASLOCO_MODEL_PATH .
			DIRECTORY_SEPARATOR .
			'class-trasloco-handler.php';
