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

class Trasloco_Backups {

	/**
	 * Get all backup files
	 *
	 * @return array
	 */
	public function get_files() {
		$backups = array();

		// Iterate over directory
		$iterator = new Trasloco_Recursive_Directory_Iterator( TRASLOCO_BACKUPS_PATH );

		// Filter by extensions
		$iterator = new Trasloco_Recursive_Extension_Filter( $iterator, array( 'wpress' ) );

		// Recursively iterate over directory
		$iterator = new Trasloco_Recursive_Iterator_Iterator( $iterator, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );

		// Get backup files
		foreach ( $iterator as $item ) {
			try {
				if ( trasloco_is_filesize_supported( $item->getPathname() ) ) {
					$backups[] = array(
						'path'     => $iterator->getSubPath(),
						'filename' => $iterator->getSubPathname(),
						'mtime'    => $iterator->getMTime(),
						'size'     => $iterator->getSize(),
					);
				} else {
					$backups[] = array(
						'path'     => $iterator->getSubPath(),
						'filename' => $iterator->getSubPathname(),
						'mtime'    => $iterator->getMTime(),
						'size'     => null,
					);
				}
			} catch ( Exception $e ) {
				$backups[] = array(
					'path'     => $iterator->getSubPath(),
					'filename' => $iterator->getSubPathname(),
					'mtime'    => null,
					'size'     => null,
				);
			}
		}

		// Sort backups modified date
		usort( $backups, array( $this, 'compare' ) );

		return $backups;
	}

	/**
	 * Automatic retention only applies to ordinary backups. Recovery points
	 * need explicit administrator deletion, even if created by update hooks.
	 * A filename prefix survives migration when the job ledger is replaced.
	 */
	public static function is_recovery_point( $filename ) {
		return is_string( $filename ) && strpos( basename( $filename ), 'recovery-' ) === 0;
	}

	/** Select a bounded retention set across full archives and compacted catalogs. */
	public static function incremental_retention_names( $archives, $snapshots, $keep ) {
		if ( ! is_array( $archives ) || ! is_array( $snapshots ) || ! is_int( $keep ) || $keep < 1 ) {
			throw new InvalidArgumentException( 'Invalid incremental retention inputs.' );
		}
		$candidates = array();
		foreach ( $archives as $archive ) {
			$name = basename( $archive['filename'] );
			if ( ! self::is_recovery_point( $name ) ) {
				$candidates[ $name ] = array( 'archive' => $name, 'mtime' => (int) $archive['mtime'] );
			}
		}
		foreach ( $snapshots as $snapshot ) {
			$name = basename( $snapshot['archive'] );
			if ( ! self::is_recovery_point( $name ) && ! isset( $candidates[ $name ] ) ) {
				$candidates[ $name ] = array( 'archive' => $name, 'mtime' => (int) $snapshot['mtime'] );
			}
		}
		usort( $candidates, function ( $a, $b ) {
			return $a['mtime'] === $b['mtime'] ? strcmp( $a['archive'], $b['archive'] ) : ( $a['mtime'] > $b['mtime'] ? -1 : 1 );
		} );
		return array_values( array_map( function ( $candidate ) { return $candidate['archive']; }, array_slice( $candidates, 0, $keep ) ) );
	}

	/** @return array Automatic retention candidates, newest first. */
	public function get_retention_files() {
		return array_values( array_filter( $this->get_files(), function ( $backup ) {
			return ! self::is_recovery_point( $backup['filename'] );
		} ) );
	}

	/**
	 * Delete file
	 *
	 * @param  string  $file File name
	 * @return boolean
	 */
	public function delete_file( $file ) {
		if ( validate_file( $file ) === 0 ) {
			$archive = trasloco_backup_path( array( 'archive' => $file ) );
			$deleted = @unlink( $archive );
			if ( $deleted ) {
				@unlink( $archive . '.manifest.json' );
			}
			return $deleted;
		}
	}

	/**
	 * Compare backup files by modified time
	 *
	 * @param  array $a File item A
	 * @param  array $b File item B
	 * @return integer
	 */
	public function compare( $a, $b ) {
		if ( $a['mtime'] === $b['mtime'] ) {
			return 0;
		}

		return ( $a['mtime'] > $b['mtime'] ) ? - 1 : 1;
	}
}
