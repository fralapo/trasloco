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

class Trasloco_Main_Controller {

	/**
	 * Main Application Controller
	 *
	 * @return Trasloco_Main_Controller
	 */
	public function __construct() {
		register_activation_hook( TRASLOCO_PLUGIN_BASENAME, array( $this, 'activation_hook' ) );

		// Activate hooks
		$this->activate_actions();
		$this->activate_filters();
	}

	/**
	 * Activation hook callback
	 *
	 * @return void
	 */
	public function activation_hook() {
		if ( is_dir( TRASLOCO_BACKUPS_PATH ) ) {
			$this->create_backups_htaccess( TRASLOCO_BACKUPS_HTACCESS );
			$this->create_backups_webconfig( TRASLOCO_BACKUPS_WEBCONFIG );
			$this->create_backups_index( TRASLOCO_BACKUPS_INDEX );
		}

		if ( extension_loaded( 'litespeed' ) ) {
			$this->create_litespeed_htaccess( TRASLOCO_WORDPRESS_HTACCESS );
		}
	}

	/**
	 * Register listeners for actions
	 *
	 * @return void
	 */
	private function activate_actions() {
		// Init
		add_action( 'admin_init', array( $this, 'init' ) );

		// Router
		add_action( 'admin_init', array( $this, 'router' ) );

		// Setup folders
		add_action( 'admin_init', array( $this, 'setup_folders' ) );

		// Admin header
		add_action( 'admin_head', array( $this, 'admin_head' ) );

		// Plugin loaded
		add_action( 'plugins_loaded', array( $this, 'trasloco_loaded' ), 10 );

		// Export and import commands
		add_action( 'plugins_loaded', array( $this, 'trasloco_commands' ), 10 );

		// Register scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts_and_styles' ), 5 );

		// Styles for the plugin pages
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_trasloco_style' ), 20 );

		// Enqueue export scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_export_scripts_and_styles' ), 5 );

		// Enqueue import scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_import_scripts_and_styles' ), 5 );

		// Enqueue backups scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_backups_scripts_and_styles' ), 5 );
	}

	/**
	 * Register listeners for filters
	 *
	 * @return void
	 */
	private function activate_filters() {
		// Add custom schedules
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ), 9999 );
	}

	/**
	 * Export and import commands
	 *
	 * @return void
	 */
	public function trasloco_commands() {
		// Add export commands
		add_filter( 'trasloco_export', 'Trasloco_Export_Init::execute', 5 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Archive::execute', 10 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Config::execute', 50 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Config_File::execute', 60 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Enumerate::execute', 100 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Content::execute', 150 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Database::execute', 200 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Database_File::execute', 220 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Download::execute', 250 );
		add_filter( 'trasloco_export', 'Trasloco_Export_Clean::execute', 300 );

		// Add import commands
		add_filter( 'trasloco_import', 'Trasloco_Import_Upload::execute', 5 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Validate::execute', 10 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Confirm::execute', 100 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Blogs::execute', 150 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Enumerate::execute', 200 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Recovery::execute', 225 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Content::execute', 250 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Mu_Plugins::execute', 270 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Database::execute', 300 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Done::execute', 350 );
		add_filter( 'trasloco_import', 'Trasloco_Import_Clean::execute', 400 );
	}

	/**
	 * Plugin loaded
	 *
	 * @return void
	 */
	public function trasloco_loaded() {
		if ( is_multisite() ) {
			add_action( 'network_admin_notices', array( $this, 'multisite_notice' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
            add_action( 'admin_notices', array( $this, 'nginx_backup_security_notice' ) );
		}

		// Add HTTP export headers
		add_filter( 'trasloco_http_export_headers', 'Trasloco_Export_Controller::http_export_headers' );

		// Add HTTP import headers
		add_filter( 'trasloco_http_import_headers', 'Trasloco_Import_Controller::http_import_headers' );

		// Add chunk size limit
		add_filter( 'trasloco_max_chunk_size', 'Trasloco_Import_Controller::max_chunk_size' );
	}

	/**
	 * Create folders and files needed for plugin operation, if they don't exist
	 *
	 * @return void
	 */
	public function setup_folders() {
		// Check if storage folder is created
		if ( ! is_dir( TRASLOCO_STORAGE_PATH ) ) {
			$this->create_storage_folder( TRASLOCO_STORAGE_PATH );
		}

		// Check if backups folder is created
		if ( ! is_dir( TRASLOCO_BACKUPS_PATH ) ) {
			$this->create_backups_folder( TRASLOCO_BACKUPS_PATH );
		}

		// Check if index.php is created in storage folder
		if ( ! is_file( TRASLOCO_STORAGE_INDEX ) ) {
			$this->create_storage_index( TRASLOCO_STORAGE_INDEX );
		}

		// Check if index.php is created in backups folder
		if ( ! is_file( TRASLOCO_BACKUPS_INDEX ) ) {
			$this->create_backups_index( TRASLOCO_BACKUPS_INDEX );
		}

		// Check if .htaccess is created in backups folder
		if ( ! is_file( TRASLOCO_BACKUPS_HTACCESS ) ) {
			$this->create_backups_htaccess( TRASLOCO_BACKUPS_HTACCESS );
		}

		// Check if web.config is created in backups folder
		if ( ! is_file( TRASLOCO_BACKUPS_WEBCONFIG ) ) {
			$this->create_backups_webconfig( TRASLOCO_BACKUPS_WEBCONFIG );
		}

		// Deny direct HTTP access to temporary SQL dumps, logs and archives.
		$storage_rules = TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! is_file( $storage_rules ) ) {
			@file_put_contents( $storage_rules, implode( PHP_EOL, array( '<IfModule mod_authz_core.c>', 'Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', 'Deny from all', '</IfModule>' ) ), LOCK_EX );
		}
		$storage_webconfig = TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . 'web.config';
		if ( ! is_file( $storage_webconfig ) ) {
			@file_put_contents( $storage_webconfig, '<configuration><system.webServer><security><authorization><deny users="*" /></authorization></security></system.webServer></configuration>', LOCK_EX );
		}
		// Upgrade existing Apache archive rules without discarding local directives.
		if ( is_file( TRASLOCO_BACKUPS_HTACCESS ) ) {
			$access_rules = @file_get_contents( TRASLOCO_BACKUPS_HTACCESS );
			if ( is_string( $access_rules ) && strpos( $access_rules, '# Trasloco private archives' ) === false ) {
				$private_rules = array( '# Trasloco private archives', '<FilesMatch "\\.wpress(\\.manifest\\.json|\\.enc)?$">', '<IfModule mod_authz_core.c>', 'Require all denied', '</IfModule>', '<IfModule !mod_authz_core.c>', 'Deny from all', '</IfModule>', '</FilesMatch>' );
				@file_put_contents( TRASLOCO_BACKUPS_HTACCESS, PHP_EOL . implode( PHP_EOL, $private_rules ) . PHP_EOL, FILE_APPEND | LOCK_EX );
			}
		}
		// Existing Apache installations need a full directory denial too:
		// decrypt/write operations create private temporary filenames whose
		// extensions are not necessarily .wpress.
		if ( is_file( TRASLOCO_BACKUPS_HTACCESS ) ) {
			$existing = @file_get_contents( TRASLOCO_BACKUPS_HTACCESS );
			if ( is_string( $existing ) && strpos( $existing, '# Trasloco private directory' ) === false ) {
				$deny = array( '# Trasloco private directory',
					'<IfModule mod_authz_core.c>', 'Require all denied', '</IfModule>',
					'<IfModule !mod_authz_core.c>', 'Order allow,deny', 'Deny from all', '</IfModule>' );
				@file_put_contents( TRASLOCO_BACKUPS_HTACCESS, PHP_EOL . implode( PHP_EOL, $deny ) . PHP_EOL, FILE_APPEND | LOCK_EX );
			}
		}
		// Remove work folders of imports and exports that stopped halfway: nothing has written to them for a day
		foreach ( (array) glob( TRASLOCO_STORAGE_PATH . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR ) as $folder ) {
			$last = (int) @filemtime( $folder );
			foreach ( (array) glob( $folder . DIRECTORY_SEPARATOR . '*' ) as $file ) {
				$last = max( $last, (int) @filemtime( $file ) );
			}
			if ( $last < time() - DAY_IN_SECONDS ) {
				Trasloco_Directory::delete( $folder );
			}
		}
	}

	/**
	 * Create storage folder
	 *
	 * @param  string Path to folder
	 * @return void
	 */
	public function create_storage_folder( $path ) {
		if ( ! Trasloco_Directory::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_path_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_path_notice' ) );
			}
		}
	}

	/**
	 * Create backups folder
	 *
	 * @param  string Path to folder
	 * @return void
	 */
	public function create_backups_folder( $path ) {
		if ( ! Trasloco_Directory::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_path_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_path_notice' ) );
			}
		}
	}

	/**
	 * Create storage index.php file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_storage_index( $path ) {
		if ( ! Trasloco_File_Index::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'storage_index_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'storage_index_notice' ) );
			}
		}
	}

	/**
	 * Create backups .htaccess file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_htaccess( $path ) {
		if ( ! Trasloco_File_Htaccess::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_htaccess_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_htaccess_notice' ) );
			}
		}
	}

	/**
	 * Create backups web.config file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_webconfig( $path ) {
		if ( ! Trasloco_File_Webconfig::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_webconfig_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_webconfig_notice' ) );
			}
		}
	}

	/**
	 * Create backups index.php file
	 *
	 * @param  string Path to file
	 * @return void
	 */
	public function create_backups_index( $path ) {
		if ( ! Trasloco_File_Index::create( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'backups_index_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'backups_index_notice' ) );
			}
		}
	}

	/**
	 * If the "noabort" environment variable has been set,
	 * the script will continue to run even though the connection has been broken
	 *
	 * @return void
	 */
	public function create_litespeed_htaccess( $path ) {
		if ( ! Trasloco_File_Htaccess::litespeed( $path ) ) {
			if ( is_multisite() ) {
				return add_action( 'network_admin_notices', array( $this, 'wordpress_htaccess_notice' ) );
			} else {
				return add_action( 'admin_notices', array( $this, 'wordpress_htaccess_notice' ) );
			}
		}
	}

	/**
	 * Best-effort warning: Nginx bypasses our .htaccess access controls.
	 * Only warn when its detected document root contains our backup folder.
	 */
	public function nginx_backup_security_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] )
			? $_SERVER['SERVER_SOFTWARE'] : '';
		if ( stripos( $server, 'nginx' ) === false ) {
			return;
		}
		$webroot = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] )
			? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
		$backups = realpath( TRASLOCO_BACKUPS_PATH );
		if ( $webroot && $backups &&
			strpos( $backups, rtrim( $webroot, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) !== 0 &&
			$backups !== $webroot ) {
			return; // Already outside the detected public document root.
		}
		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'Trasloco security: Nginx does not honor .htaccess. Block HTTP access to the backups folder in Nginx, or configure TRASLOCO_BACKUPS_DIR outside the public web root before storing backups.', TRASLOCO_PLUGIN_NAME ) .
			'</p></div>';
	}

	/**
	 * Display multisite notice
	 *
	 * @return void
	 */
	public function multisite_notice() {
		Trasloco_Template::render( 'main/multisite-notice' );
	}

	/**
	 * Display notice for storage directory
	 *
	 * @return void
	 */
	public function storage_path_notice() {
		Trasloco_Template::render( 'main/storage-path-notice' );
	}

	/**
	 * Display notice for index file in storage directory
	 *
	 * @return void
	 */
	public function storage_index_notice() {
		Trasloco_Template::render( 'main/storage-index-notice' );
	}

	/**
	 * Display notice for backups directory
	 *
	 * @return void
	 */
	public function backups_path_notice() {
		Trasloco_Template::render( 'main/backups-path-notice' );
	}

	/**
	 * Display notice for .htaccess file in backups directory
	 *
	 * @return void
	 */
	public function backups_htaccess_notice() {
		Trasloco_Template::render( 'main/backups-htaccess-notice' );
	}

	/**
	 * Display notice for web.config file in backups directory
	 *
	 * @return void
	 */
	public function backups_webconfig_notice() {
		Trasloco_Template::render( 'main/backups-webconfig-notice' );
	}

	/**
	 * Display notice for index file in backups directory
	 *
	 * @return void
	 */
	public function backups_index_notice() {
		Trasloco_Template::render( 'main/backups-index-notice' );
	}

	/**
	 * Display notice for .htaccess file in WordPress directory
	 *
	 * @return void
	 */
	public function wordpress_htaccess_notice() {
		Trasloco_Template::render( 'main/wordpress-htaccess-notice' );
	}

	/**
	 * Load the plugin stylesheet and script only on the plugin pages
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_trasloco_style( $hook ) {
		if ( strpos( $hook, 'trasloco_' ) === false ) {
			return;
		}

		wp_enqueue_style( 'trasloco', Trasloco_Template::asset_link( 'css/trasloco.css' ), array( 'dashicons' ), (string) filemtime( TRASLOCO_PATH . '/lib/view/assets/css/trasloco.css' ) );
		wp_enqueue_script( 'trasloco', Trasloco_Template::asset_link( 'javascript/trasloco.js' ), array(), (string) filemtime( TRASLOCO_PATH . '/lib/view/assets/javascript/trasloco.js' ), true );
		wp_localize_script( 'trasloco', 'trasloco_ui', array(
			'replacement' => __( 'Replacement %d', TRASLOCO_PLUGIN_NAME ),
			'covered'     => __( 'Not needed now: the option above already leaves all of them out.', TRASLOCO_PLUGIN_NAME ),
			'deleted'     => __( 'The backup has been deleted.', TRASLOCO_PLUGIN_NAME ),
		) );
	}

	/**
	 * Register plugin menus
	 *
	 * @return void
	 */
	public function admin_menu() {
		// Top-level menu
		add_menu_page(
			'Trasloco',
			'Trasloco',
			'manage_options',
			'trasloco_dashboard',
			'Trasloco_Main_Controller::dashboard',
			// Monochrome SVG: WordPress recolors it to match the admin color scheme
			'data:image/svg+xml;base64,' . base64_encode( file_get_contents( TRASLOCO_PATH . '/lib/view/assets/img/menu-icon.svg' ) ),
			'76.295'
		);

		add_submenu_page(
			'trasloco_dashboard',
			__( 'Overview', TRASLOCO_PLUGIN_NAME ),
			__( 'Overview', TRASLOCO_PLUGIN_NAME ),
			'manage_options',
			'trasloco_dashboard',
			'Trasloco_Main_Controller::dashboard'
		);

		// Sub-level Export menu
		add_submenu_page(
			'trasloco_dashboard',
			__( 'Export', TRASLOCO_PLUGIN_NAME ),
			__( 'Export', TRASLOCO_PLUGIN_NAME ),
			'manage_options',
			'trasloco_export',
			'Trasloco_Export_Controller::index'
		);

		// Sub-level Import menu
		add_submenu_page(
			'trasloco_dashboard',
			__( 'Import', TRASLOCO_PLUGIN_NAME ),
			__( 'Import', TRASLOCO_PLUGIN_NAME ),
			'manage_options',
			'trasloco_import',
			'Trasloco_Import_Controller::index'
		);

		// Sub-level Backups menu
		add_submenu_page(
			'trasloco_dashboard',
			__( 'Backups', TRASLOCO_PLUGIN_NAME ),
			__( 'Backups', TRASLOCO_PLUGIN_NAME ),
			'manage_options',
			'trasloco_backups',
			'Trasloco_Backups_Controller::index'
		);

		// Sub-level Guide menu
		add_submenu_page(
			'trasloco_dashboard',
			__( 'Guide', TRASLOCO_PLUGIN_NAME ),
			__( 'Guide', TRASLOCO_PLUGIN_NAME ),
			'manage_options',
			'trasloco_guide',
			'Trasloco_Main_Controller::guide'
		);
	}

	/**
	 * Guide page
	 *
	 * @return void
	 */
	public static function dashboard() {
		Trasloco_Template::render( 'main/dashboard' );
	}

	public static function guide() {
		Trasloco_Template::render( 'guide/index' );
	}

	/**
	 * Register scripts and styles
	 *
	 * @return void
	 */
	public function register_scripts_and_styles() {
		if ( is_rtl() ) {
			wp_register_style(
				'trasloco_base',
				Trasloco_Template::asset_link( 'css/base.min.rtl.css' )
			);
		} else {
			wp_register_style(
				'trasloco_base',
				Trasloco_Template::asset_link( 'css/base.min.css' )
			);
		}

		wp_register_script(
			'trasloco_util',
			Trasloco_Template::asset_link( 'javascript/util.min.js' ),
			array( 'jquery' )
		);


	}

	/**
	 * Enqueue scripts and styles for Export Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_export_scripts_and_styles( $hook ) {
		if ( strpos( (string) $hook, '_page_trasloco_export' ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when exporting
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		if ( is_rtl() ) {
			wp_enqueue_style(
				'trasloco_export',
				Trasloco_Template::asset_link( 'css/export.min.rtl.css' )
			);
		} else {
			wp_enqueue_style(
				'trasloco_export',
				Trasloco_Template::asset_link( 'css/export.min.css' )
			);
		}

		wp_enqueue_script(
			'trasloco_export',
			Trasloco_Template::asset_link( 'javascript/export.min.js' ),
			array( 'trasloco_util' )
		);

		wp_localize_script( 'trasloco_export', 'trasloco_feedback', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_feedback' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'export' ),
		) );

		wp_localize_script( 'trasloco_export', 'trasloco_report', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_report' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'export' ),
		) );

		wp_localize_script( 'trasloco_export', 'trasloco_export', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_export' ) ),
			),
			'status'     => array(
				'url' => wp_make_link_relative( add_query_arg( array( 'secret_key' => trasloco_issue_operation_token( 'export' ),
			'download_nonce' => wp_create_nonce( 'trasloco_download_file' ), 'operation' => 'export' ), admin_url( 'admin-ajax.php?action=trasloco_status' ) ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'export' ),
		) );

		wp_localize_script( 'trasloco_export', 'trasloco_locale', array(
			'stop_exporting_your_website'         => __( 'You are about to stop exporting your website, are you sure?', TRASLOCO_PLUGIN_NAME ),
			'preparing_to_export'                 => __( 'Preparing to export...', TRASLOCO_PLUGIN_NAME ),
			'unable_to_export'                    => __( 'The export stopped', TRASLOCO_PLUGIN_NAME ),
			'unable_to_start_the_export'          => __( 'The export could not start. Refresh the page and click Create export file again.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_run_the_export'            => __( 'The export stopped before the file was finished. Your site has not changed. Refresh the page and click Create export file again.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_stop_the_export'           => __( 'Trasloco could not stop the export. Refresh the page.', TRASLOCO_PLUGIN_NAME ),
			'please_wait_stopping_the_export'     => __( 'Please wait, stopping the export...', TRASLOCO_PLUGIN_NAME ),
			'close_export'                        => __( 'Close', TRASLOCO_PLUGIN_NAME ),
			'stop_export'                         => __( 'Stop export', TRASLOCO_PLUGIN_NAME ),
		) );
	}

	/**
	 * Enqueue scripts and styles for Import Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_import_scripts_and_styles( $hook ) {
		if ( strpos( (string) $hook, '_page_trasloco_import' ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when importing
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		if ( is_rtl() ) {
			wp_enqueue_style(
				'trasloco_import',
				Trasloco_Template::asset_link( 'css/import.min.rtl.css' )
			);
		} else {
			wp_enqueue_style(
				'trasloco_import',
				Trasloco_Template::asset_link( 'css/import.min.css' )
			);
		}

		wp_enqueue_script(
			'trasloco_import',
			Trasloco_Template::asset_link( 'javascript/import.min.js' ),
			array( 'trasloco_util' )
		);

		wp_localize_script( 'trasloco_import', 'trasloco_feedback', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_feedback' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'import' ),
		) );

		wp_localize_script( 'trasloco_import', 'trasloco_report', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_report' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'import' ),
		) );

		wp_localize_script( 'trasloco_import', 'trasloco_uploader', array(
			'chunk_size'  => apply_filters( 'trasloco_max_chunk_size', TRASLOCO_MAX_CHUNK_SIZE ),
			'max_retries' => apply_filters( 'trasloco_max_chunk_retries', TRASLOCO_MAX_CHUNK_RETRIES ),
			'url'         => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_import' ) ),
			'params'      => array(
				'priority'   => 5,
				'secret_key' => trasloco_issue_operation_token( 'import' ),
			),
			'filters'     => array(
				'trasloco_archive_extension' => array( 'wpress' ),
				'trasloco_archive_size'      => apply_filters( 'trasloco_max_file_size', TRASLOCO_MAX_FILE_SIZE ),
			),
		) );

		wp_localize_script( 'trasloco_import', 'trasloco_import', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_import' ) ),
			),
			'status'     => array(
				'url' => wp_make_link_relative( add_query_arg( array( 'secret_key' => trasloco_issue_operation_token( 'import' ), 'operation' => 'import' ), admin_url( 'admin-ajax.php?action=trasloco_status' ) ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'import' ),
		) );

		wp_localize_script( 'trasloco_import', 'trasloco_locale', array(
			'stop_importing_your_website'         => __( 'You are about to stop importing your website, are you sure?', TRASLOCO_PLUGIN_NAME ),
			'preparing_to_import'                 => __( 'Preparing to import...', TRASLOCO_PLUGIN_NAME ),
			'unable_to_import'                    => __( 'The import stopped', TRASLOCO_PLUGIN_NAME ),
			'unable_to_start_the_import'          => __( 'The import could not start. Nothing on this site has changed. Refresh the page and choose the file again.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_confirm_the_import'        => __( 'Your confirmation did not reach the server, so nothing has been replaced yet. Refresh the page and start the import again.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_prepare_blogs_on_import'   => __( 'The import could not read the site details stored in the file. Refresh the page and try again. If it fails again, export the old site again and use the new file.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_stop_the_import'           => __( 'Trasloco could not stop the import. Refresh the page. If you had already clicked Replace site, import the same file again so that the site is complete.', TRASLOCO_PLUGIN_NAME ),
			'please_wait_stopping_the_export'     => __( 'Please wait, stopping the import...', TRASLOCO_PLUGIN_NAME ),
			'close_import'                        => __( 'Close', TRASLOCO_PLUGIN_NAME ),
			'stop_import'                         => __( 'Stop import', TRASLOCO_PLUGIN_NAME ),
			'confirm_import'                      => __( 'Replace site', TRASLOCO_PLUGIN_NAME ),
			'cancel_import'                       => __( 'Cancel, keep this site as it is', TRASLOCO_PLUGIN_NAME ),
			'continue_import'                     => __( 'Continue', TRASLOCO_PLUGIN_NAME ),
			'please_do_not_close_this_browser'    => __( 'Keep this tab open until the end. If you close it, the import stops halfway and this site may be left partly replaced.', TRASLOCO_PLUGIN_NAME ),
			'uploading_the_file'                  => __( 'Uploading the file to this site. Nothing has been replaced yet. Keep this tab open.', TRASLOCO_PLUGIN_NAME ),
			'problem_while_uploading_your_file'   => __( 'The upload stopped: the file could not be sent to this site, even after many attempts. Check your internet connection, refresh the page and choose the file again. If it keeps failing, ask your hosting company whether a firewall blocks uploads to wp-admin/admin-ajax.php.', TRASLOCO_PLUGIN_NAME ),
			'invalid_archive_extension'           => __( 'This is not a Trasloco export file. Choose the file whose name ends in <strong>.wpress</strong>, created on the old site in Trasloco → Export.', TRASLOCO_PLUGIN_NAME ),
			'invalid_archive_size'                => sprintf(
				__( 'The file is larger than the upload limit of <strong>%s</strong>.', TRASLOCO_PLUGIN_NAME ),
				size_format( apply_filters( 'trasloco_max_file_size', TRASLOCO_MAX_FILE_SIZE ) )
			),
		) );
	}

	/**
	 * Enqueue scripts and styles for Backups Controller
	 *
	 * @param  string $hook Hook suffix
	 * @return void
	 */
	public function enqueue_backups_scripts_and_styles( $hook ) {
		if ( strpos( (string) $hook, '_page_trasloco_backups' ) === false ) {
			return;
		}

		// We don't want heartbeat to occur when restoring
		wp_deregister_script( 'heartbeat' );

		// We don't want auth check for monitoring whether the user is still logged in
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );

		if ( is_rtl() ) {
			wp_enqueue_style(
				'trasloco_backups',
				Trasloco_Template::asset_link( 'css/backups.min.rtl.css' )
			);
		} else {
			wp_enqueue_style(
				'trasloco_backups',
				Trasloco_Template::asset_link( 'css/backups.min.css' )
			);
		}

		wp_enqueue_script(
			'trasloco_backups',
			Trasloco_Template::asset_link( 'javascript/backups.min.js' ),
			array( 'trasloco_util' )
		);

		wp_localize_script( 'trasloco_backups', 'trasloco_feedback', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_feedback' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'import' ),
		) );

		wp_localize_script( 'trasloco_backups', 'trasloco_report', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_report' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'import' ),
		) );

		wp_localize_script( 'trasloco_backups', 'trasloco_import', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_import' ) ),
			),
			'status'     => array(
				'url' => wp_make_link_relative( add_query_arg( array( 'secret_key' => trasloco_issue_operation_token( 'import' ), 'operation' => 'import' ), admin_url( 'admin-ajax.php?action=trasloco_status' ) ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'import' ),
		) );

		wp_localize_script( 'trasloco_backups', 'trasloco_backups', array(
			'ajax'       => array(
				'url' => wp_make_link_relative( admin_url( 'admin-ajax.php?action=trasloco_backups' ) ),
			),
			'secret_key' => trasloco_issue_operation_token( 'backups' ),
		) );

		wp_localize_script( 'trasloco_backups', 'trasloco_locale', array(
			'stop_importing_your_website'         => __( 'You are about to stop importing your website, are you sure?', TRASLOCO_PLUGIN_NAME ),
			'preparing_to_import'                 => __( 'Preparing to import...', TRASLOCO_PLUGIN_NAME ),
			'unable_to_import'                    => __( 'The import stopped', TRASLOCO_PLUGIN_NAME ),
			'unable_to_start_the_import'          => __( 'The import could not start. Nothing on this site has changed. Refresh the page and choose the file again.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_confirm_the_import'        => __( 'Your confirmation did not reach the server, so nothing has been replaced yet. Refresh the page and start the import again.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_prepare_blogs_on_import'   => __( 'The import could not read the site details stored in the file. Refresh the page and try again. If it fails again, export the old site again and use the new file.', TRASLOCO_PLUGIN_NAME ),
			'unable_to_stop_the_import'           => __( 'Trasloco could not stop the import. Refresh the page. If you had already clicked Replace site, import the same file again so that the site is complete.', TRASLOCO_PLUGIN_NAME ),
			'please_wait_stopping_the_export'     => __( 'Please wait, stopping the import...', TRASLOCO_PLUGIN_NAME ),
			'close_import'                        => __( 'Close', TRASLOCO_PLUGIN_NAME ),
			'stop_import'                         => __( 'Stop import', TRASLOCO_PLUGIN_NAME ),
			'confirm_import'                      => __( 'Replace site', TRASLOCO_PLUGIN_NAME ),
			'cancel_import'                       => __( 'Cancel, keep this site as it is', TRASLOCO_PLUGIN_NAME ),
			'continue_import'                     => __( 'Continue', TRASLOCO_PLUGIN_NAME ),
			'please_do_not_close_this_browser'    => __( 'Keep this tab open until the end. If you close it, the import stops halfway and this site may be left partly replaced.', TRASLOCO_PLUGIN_NAME ),
			'want_to_delete_this_file'            => __( 'Delete this backup from the server? This cannot be undone. The site itself does not change.', TRASLOCO_PLUGIN_NAME ),
		) );
	}

	/**
	 * Outputs menu icon between head tags
	 *
	 * @return void
	 */
	public function admin_head() {
		global $wp_version;

		// Admin header
		Trasloco_Template::render( 'main/admin-head', array( 'version' => $wp_version ) );
	}

	/**
	 * Register initial parameters
	 *
	 * @return void
	 */
	public function init() {

		// Set secret key
		if ( ! get_option( TRASLOCO_SECRET_KEY ) ) {
			update_option( TRASLOCO_SECRET_KEY, wp_generate_password( 12, false ) );
		}

		// Set username
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			update_option( TRASLOCO_AUTH_USER, $_SERVER['PHP_AUTH_USER'] );
		} elseif ( isset( $_SERVER['REMOTE_USER'] ) ) {
			update_option( TRASLOCO_AUTH_USER, $_SERVER['REMOTE_USER'] );
		}

		// Set password
		if ( isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			$encrypted = trasloco_encrypt_http_password( $_SERVER['PHP_AUTH_PW'] );
			if ( $encrypted !== false ) {
				update_option( TRASLOCO_AUTH_PASSWORD, $encrypted );
			} else {
				delete_option( TRASLOCO_AUTH_PASSWORD );
			}
		} else {
			// Remove passwords stored by older versions, without exposing them.
			$stored = get_option( TRASLOCO_AUTH_PASSWORD );
			if ( is_string( $stored ) && $stored !== '' && substr( $stored, 0, 3 ) !== 'v1:' ) {
				delete_option( TRASLOCO_AUTH_PASSWORD );
			}
		}
	}

	/**
	 * Register initial router
	 *
	 * @return void
	 */
	public function router() {
		// Public actions
		add_action( 'wp_ajax_nopriv_trasloco_export', 'Trasloco_Export_Controller::export' );
		add_action( 'wp_ajax_nopriv_trasloco_import', 'Trasloco_Import_Controller::import' );
		add_action( 'wp_ajax_nopriv_trasloco_status', 'Trasloco_Status_Controller::status' );
		// Backup deletion requires an authenticated administrator.

		// Private actions
		add_action( 'wp_ajax_trasloco_export', 'Trasloco_Export_Controller::export' );
		add_action( 'wp_ajax_trasloco_import', 'Trasloco_Import_Controller::import' );
		add_action( 'wp_ajax_trasloco_status', 'Trasloco_Status_Controller::status' );
		add_action( 'wp_ajax_trasloco_backups', 'Trasloco_Backups_Controller::delete' );
		add_action( 'admin_post_trasloco_download', 'Trasloco_Backups_Controller::download' );
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param  array $schedules List of schedules
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['weekly']  = array(
			'display'  => __( 'Weekly', TRASLOCO_PLUGIN_NAME ),
			'interval' => 60 * 60 * 24 * 7,
		);
		$schedules['monthly'] = array(
			'display'  => __( 'Monthly', TRASLOCO_PLUGIN_NAME ),
			'interval' => ( strtotime( '+1 month' ) - time() ),
		);

		return $schedules;
	}
}
