<?php
require_once dirname( __DIR__ ) . '/exceptions.php';
require_once dirname( __DIR__ ) . '/lib/vendor/core/archiver/class-trasloco-integrity.php';

$root = sys_get_temp_dir() . '/trasloco-integrity-compat-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $root, 0700 ) ) {
	throw new RuntimeException( 'Could not create integrity compatibility fixture.' );
}
$archive = $root . '/feature-v1.wpress';
$sidecar = Trasloco_Integrity::manifest_path( $archive );
$bytes = "feature-branch manifest compatibility fixture\n";

try {
	file_put_contents( $archive, $bytes );
	$legacy = array(
		'schema' => 1,
		'format' => 'wpress',
		'archive' => basename( $archive ),
		'bytes' => strlen( $bytes ),
		'sha256' => hash( 'sha256', $bytes ),
		'created_at' => gmdate( 'c' ),
	);
	file_put_contents( $sidecar, json_encode( $legacy ) );
	if ( Trasloco_Integrity::verify( $archive )['status'] !== 'verified' ||
		Trasloco_Integrity::chunked_manifest( $archive ) !== null ) {
		throw new RuntimeException( 'Valid feature-branch schema-1 sidecar was not accepted as whole-file SHA-256.' );
	}

	$wrong_archive = $legacy;
	$wrong_archive['archive'] = 'another.wpress';
	file_put_contents( $sidecar, json_encode( $wrong_archive ) );
	if ( Trasloco_Integrity::verify( $archive )['status'] !== 'invalid' ) {
		throw new RuntimeException( 'Feature-branch sidecar for a different archive was accepted.' );
	}

	file_put_contents( $sidecar, json_encode( $legacy ) );
	file_put_contents( $archive, substr_replace( $bytes, 'X', 0, 1 ) );
	if ( Trasloco_Integrity::verify( $archive )['status'] !== 'corrupt' ) {
		throw new RuntimeException( 'Modified archive passed its feature-branch SHA-256 sidecar.' );
	}
} finally {
	@unlink( $sidecar );
	@unlink( $archive );
	rmdir( $root );
}

echo "Feature-branch integrity manifest compatibility tests passed.\n";
