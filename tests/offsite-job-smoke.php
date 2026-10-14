<?php
/** End-to-end persistent offsite download job fixture, loaded by WP-CLI. */

$archive = 'offsite-transfer-fixture.wpress';
$job_id = 0;
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, payload FROM " . Trasloco_Job_Store::table() . " WHERE kind = %s AND state = %s ORDER BY id DESC LIMIT 10",
	'transfer', 'pending'
), ARRAY_A );
foreach ( $rows as $row ) {
	$payload = json_decode( $row['payload'], true );
	if ( is_array( $payload ) && $payload === array( 'operation' => 'download', 'archive' => $archive ) ) {
		$job_id = (int) $row['id'];
		break;
	}
}
if ( ! $job_id ) {
	throw new RuntimeException( 'WP-CLI did not persist an offsite download job.' );
}

$record = Trasloco_Job_Store::workload( $job_id );
if ( ! is_array( $record ) || $record['kind'] !== 'transfer' ||
	$record['payload'] !== array( 'operation' => 'download', 'archive' => $archive ) ) {
	throw new RuntimeException( 'Offsite job payload is invalid or contains unexpected data.' );
}
$unsupported = Trasloco_Job_Store::create( 'transfer',
	array( 'operation' => 'upload', 'archive' => $archive ),
	wp_generate_password( 32, false, false ) );
if ( ! is_wp_error( $unsupported ) ) {
	throw new RuntimeException( 'Non-resumable offsite upload was incorrectly accepted as a job.' );
}

$test_owner = 'worker-restart-test';
if ( ! Trasloco_Job_Store::claim( $job_id, $test_owner, 30 ) ) {
	throw new RuntimeException( 'Offsite download job could not be claimed.' );
}
$wpdb->update( Trasloco_Job_Store::table(), array( 'lease_until' => 1 ), array( 'id' => $job_id ), array( '%d' ), array( '%d' ) );
if ( ! in_array( (int) $job_id, Trasloco_Job_Store::recoverable_transfers(), true ) ) {
	throw new RuntimeException( 'Expired offsite download lease was not recoverable.' );
}

$result = Trasloco_Job_Worker::run( $job_id );
$job = Trasloco_Job_Store::get( $job_id );
$local = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $archive;
$remote = TRASLOCO_REMOTE_DIRECTORY;
if ( ! is_array( $result ) || $result['status'] !== 'completed' ||
	! is_array( $job ) || $job['state'] !== 'completed' || $job['progress'] !== 100 ||
	! isset( $job['checkpoint']['phase'], $job['checkpoint']['bytes'] ) ||
	$job['checkpoint']['phase'] !== 'verified' || ! is_file( $local ) ||
	! is_string( $remote ) || ! is_file( $remote . DIRECTORY_SEPARATOR . $archive ) ||
	hash_file( 'sha256', $local ) !== hash_file( 'sha256', $remote . DIRECTORY_SEPARATOR . $archive ) ) {
	throw new RuntimeException( 'Persistent offsite download job did not finish with verified bytes.' );
}

echo "Persistent offsite download job passed.\n";
