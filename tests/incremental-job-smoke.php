<?php
/** WP-CLI integration fixture for durable incremental snapshot transfer jobs. */
require_once TRASLOCO_LIB_PATH . '/model/class-trasloco-incremental-store.php';

$operation = isset( $args[0] ) ? $args[0] : '';
$archive = 'offsite-transfer-fixture.wpress';
if ( ! in_array( $operation, array( 'incremental_upload', 'incremental_download' ), true ) ) {
	throw new RuntimeException( 'Specify an incremental upload or download job operation.' );
}
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, payload FROM " . Trasloco_Job_Store::table() . " WHERE kind = %s AND state = %s ORDER BY id DESC LIMIT 20",
	'transfer', 'pending'
), ARRAY_A );
$job_id = 0;
foreach ( $rows as $row ) {
	$payload = json_decode( $row['payload'], true );
	if ( is_array( $payload ) && isset( $payload['operation'], $payload['archive'] ) &&
		$payload['operation'] === $operation && $payload['archive'] === $archive ) {
		$job_id = (int) $row['id'];
		break;
	}
}
if ( ! $job_id ) { throw new RuntimeException( 'WP-CLI did not persist the incremental transfer job.' ); }
$record = Trasloco_Job_Store::workload( $job_id );
$expected = array( 'operation' => $operation, 'archive' => $archive );
if ( $operation === 'incremental_upload' ) { $expected['allow_unencrypted'] = true; }
if ( ! is_array( $record ) || $record['payload'] !== $expected ) {
	throw new RuntimeException( 'Incremental transfer job payload is invalid or contains unexpected data.' );
}
$unsafe = Trasloco_Job_Store::create( 'transfer', array(
	'operation' => $operation, 'archive' => $archive, 'credentials' => 'must-not-persist',
), wp_generate_password( 32, false, false ) );
if ( ! is_wp_error( $unsafe ) ) { throw new RuntimeException( 'Incremental transfer accepted unexpected credential-bearing fields.' ); }
if ( ! Trasloco_Job_Store::claim( $job_id, 'incremental-job-restart-test', 30 ) ) {
	throw new RuntimeException( 'Incremental transfer job could not be claimed.' );
}
$wpdb->update( Trasloco_Job_Store::table(), array( 'lease_until' => 1 ), array( 'id' => $job_id ), array( '%d' ), array( '%d' ) );
if ( ! in_array( $job_id, Trasloco_Job_Store::recoverable_transfers(), true ) ) {
	throw new RuntimeException( 'Expired incremental transfer lease was not recoverable.' );
}
$verification_checkpoint_seen = false;
$observed_transfer_checkpoints = array();
$observe_verification_checkpoint = function ( $query ) use ( &$verification_checkpoint_seen, &$observed_transfer_checkpoints ) {
	if ( stripos( $query, 'UPDATE' ) !== false && stripos( $query, 'trasloco_jobs' ) !== false ) {
		$observed_transfer_checkpoints[] = substr( $query, 0, 600 );
		if ( stripos( $query, 'verifying' ) !== false ) {
			$verification_checkpoint_seen = true;
		}
	}
	return $query;
};
add_filter( 'query', $observe_verification_checkpoint );
$result = Trasloco_Job_Worker::run( $job_id );
remove_filter( 'query', $observe_verification_checkpoint );
$job = Trasloco_Job_Store::get( $job_id );
if ( ! is_array( $result ) || $result['status'] !== 'completed' || ! is_array( $job ) ||
	$job['state'] !== 'completed' || $job['progress'] !== 100 ||
	! isset( $job['checkpoint']['phase'], $job['checkpoint']['archive'] ) ||
	$job['checkpoint']['phase'] !== 'verified' || $job['checkpoint']['archive'] !== $archive ) {
	throw new RuntimeException( 'Durable incremental transfer did not resume and finish with a verified checkpoint.' );
}
if ( $operation === 'incremental_download' ) {
	if ( ! $verification_checkpoint_seen ) {
		throw new RuntimeException( 'Incremental download did not persist a distinct whole-archive verification checkpoint. Observed job updates: ' . implode( ' | ', $observed_transfer_checkpoints ) );
	}
	$store = new Trasloco_Incremental_Store( rtrim( TRASLOCO_BACKUPS_PATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.trasloco-incremental' );
	$proof = $store->verify( $archive );
	$materialized = TRASLOCO_BACKUPS_PATH . DIRECTORY_SEPARATOR . $archive;
	if ( ! is_file( $materialized ) ) { $store->materialize( $archive, $materialized ); }
	if ( $proof['sha256'] !== hash_file( 'sha256', $materialized ) ) {
		throw new RuntimeException( 'Queued incremental download did not materialize the exact verified archive.' );
	}
}
echo "Durable {$operation} job passed.\n";
