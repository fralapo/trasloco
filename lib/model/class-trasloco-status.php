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

class Trasloco_Status {

	public static function error( $title, $message ) {
		self::log( array( 'type' => 'error', 'title' => $title, 'message' => $message ) );
	}

	public static function info( $message ) {
		self::log( array( 'type' => 'info', 'message' => $message ) );
	}

	public static function download( $message ) {
		self::log( array( 'type' => 'download', 'message' => $message ) );
	}

	public static function confirm( $message ) {
		self::log( array( 'type' => 'confirm', 'message' => $message ) );
	}

	public static function done( $title, $message ) {
		self::log( array( 'type' => 'done', 'title' => $title, 'message' => $message ) );
	}

	public static function blogs( $title, $message ) {
		self::log( array( 'type' => 'blogs', 'title' => $title, 'message' => $message ) );
	}

	public static function progress( $percent ) {
		self::log( array( 'type' => 'progress', 'percent' => $percent ) );
	}

	public static function log( $data ) {
		// Periodic lease renewal during streamed exports prevents the job
		// reconciler from treating an active long backup as abandoned.
		if ( ! empty( $GLOBALS['trasloco_active_backup_job'] ) ) {
			$job = &$GLOBALS['trasloco_active_backup_job'];
			if ( isset( $job['id'], $job['owner'], $job['last_renew'] ) &&
				time() - (int) $job['last_renew'] >= 60 ) {
				if ( ! Trasloco_Job_Store::renew( (int) $job['id'], $job['owner'], 3600 ) ) {
					throw new RuntimeException( 'Backup execution lease expired or was cancelled.' );
				}
				$job['last_renew'] = time();
			}
		}
		if ( ! empty( $GLOBALS['trasloco_active_export_lock'] ) ) {
			$lock = &$GLOBALS['trasloco_active_export_lock'];
			if ( isset( $lock['handle'], $lock['owner'], $lock['last_renew'] ) &&
				time() - (int) $lock['last_renew'] >= 60 ) {
				$lease_seconds = isset( $lock['lease_seconds'] ) ? (int) $lock['lease_seconds'] : Trasloco_Export_Lock::DEFAULT_LEASE_SECONDS;
				if ( ! Trasloco_Export_Lock::renew_held( $lock['handle'], $lock['owner'], $lease_seconds ) ) {
					throw new RuntimeException( 'Backup process lock expired or could not be renewed.' );
				}
				$lock['last_renew'] = time();
			}
		}
		if ( ! trasloco_is_scheduled_backup() && empty( $GLOBALS['trasloco_recovery_export_active'] ) ) {
			update_option( TRASLOCO_STATUS, $data );
		}
	}
}
