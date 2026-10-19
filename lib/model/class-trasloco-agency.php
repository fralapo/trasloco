<?php
/**
 * Optional, read-only overview of separately configured WordPress sites.
 * Site names and revocable Application Passwords stay in wp-config.php.
 */
class Trasloco_Agency {
	const CACHE_SECONDS = 300;
	const MAX_SITES = 15;
	const MAX_RESPONSE_BYTES = 16384;
	const PAGE_TIME_BUDGET_SECONDS = 10;

	/** Return only administrator-supplied site definitions, with a fixed bound. */
	public static function sites() {
		if ( ! defined( 'TRASLOCO_AGENCY_SITES' ) || ! is_array( TRASLOCO_AGENCY_SITES ) ) {
			return array();
		}

		return array_slice( TRASLOCO_AGENCY_SITES, 0, self::MAX_SITES );
	}

	public static function enabled() {
		return ! empty( self::sites() );
	}

	/** Validate a configured URL before attaching its credentials to a request. */
	private static function validate_site( $site ) {
		if ( ! is_array( $site ) || ! isset( $site['url'], $site['user'], $site['password'] ) ||
			! is_string( $site['url'] ) || ! is_string( $site['user'] ) || ! is_string( $site['password'] ) ||
			$site['url'] === '' || $site['user'] === '' || $site['password'] === '' ||
			strlen( $site['url'] ) > 2048 || strlen( $site['user'] ) > 200 || strlen( $site['password'] ) > 512 ||
			strpos( $site['user'], ':' ) !== false || preg_match( '/[\x00-\x1F\x7F]/', $site['user'] . $site['password'] ) ) {
			return false;
		}

		$parts = wp_parse_url( $site['url'] );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ||
			strtolower( $parts['scheme'] ) !== 'https' || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
			isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
			( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) ||
			! wp_http_validate_url( $site['url'] ) ) {
			return false;
		}

		return rtrim( $site['url'], '/' );
	}

	private static function count_value( $value ) {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && strlen( $value ) <= 9 && ctype_digit( $value ) ) ) {
			return null;
		}
		$count = (int) $value;
		return $count >= 0 && $count <= 999999999 ? $count : null;
	}

	/** Make a bounded, credential-safe request to the existing admin-only health API. */
	public static function inspect( $site, $timeout = 5 ) {
		$base = self::validate_site( $site );
		if ( $base === false ) {
			return array( 'status' => 'invalid' );
		}

		$url = $base . '/wp-json/trasloco/v1/health';
		$cache_key = 'trasloco_agency_' . hash( 'sha256', $url . '|' . $site['user'] );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( (int) $timeout < 1 ) {
			return array( 'status' => 'not_checked' );
		}

		$response = wp_safe_remote_get( $url, array(
			'timeout'             => max( 1, min( 5, (int) $timeout ) ),
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'headers'              => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $site['user'] . ':' . $site['password'] ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			$health = array( 'status' => 'unreachable' );
		} elseif ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$health = array( 'status' => 'unauthorized' );
		} else {
			$body = wp_remote_retrieve_body( $response );
			$data = is_string( $body ) && strlen( $body ) <= self::MAX_RESPONSE_BYTES ? json_decode( $body, true ) : null;
			$backups = is_array( $data ) && isset( $data['backups'] ) ? self::count_value( $data['backups'] ) : null;
			$active_jobs = is_array( $data ) && isset( $data['active_jobs'] ) ? self::count_value( $data['active_jobs'] ) : null;
			$failed_jobs = is_array( $data ) && isset( $data['failed_jobs'] ) ? self::count_value( $data['failed_jobs'] ) : null;
			if ( $backups === null || $active_jobs === null || $failed_jobs === null ) {
				$health = array( 'status' => 'invalid' );
			} else {
				$schedule = isset( $data['schedule'] ) && is_string( $data['schedule'] ) ? $data['schedule'] : '';
				$health = array(
					'status'      => 'connected',
					'backups'     => $backups,
					'active_jobs' => $active_jobs,
					'failed_jobs' => $failed_jobs,
					'schedule'    => in_array( $schedule, array( 'off', 'hourly', 'daily', 'weekly', 'monthly' ), true ) ? $schedule : 'unknown',
				);
			}
		}

		// Cache error states too, so a broken endpoint cannot be polled on every admin page load.
		set_transient( $cache_key, $health, self::CACHE_SECONDS );
		return $health;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', TRASLOCO_PLUGIN_NAME ) );
		}

		$sites = array();
		$deadline = microtime( true ) + self::PAGE_TIME_BUDGET_SECONDS;
		foreach ( self::sites() as $site ) {
			$name = isset( $site['name'] ) && is_string( $site['name'] ) ? trim( $site['name'] ) : '';
			$remaining = $deadline - microtime( true );
			$sites[] = array(
				'name'   => $name !== '' ? substr( $name, 0, 100 ) : __( 'WordPress site', TRASLOCO_PLUGIN_NAME ),
				'health' => self::inspect( $site, $remaining >= 0.2 ? (int) ceil( $remaining ) : 0 ),
			);
		}

		Trasloco_Template::render( 'main/agency', array( 'sites' => $sites ) );
	}
}
