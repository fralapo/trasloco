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
	array( 'operation' => 'download', 'archive' => $archive, 'credentials' => 'must-not-persist' ),
	wp_generate_password( 32, false, false ) );
if ( ! is_wp_error( $unsupported ) ) {
	throw new RuntimeException( 'Offsite job accepted an unexpected or secret-bearing payload.' );
}
$plaintext_without_consent = Trasloco_Job_Worker::enqueue_offsite_upload( $archive, false );
if ( ! is_wp_error( $plaintext_without_consent ) ||
	$plaintext_without_consent->get_error_code() !== 'trasloco_plaintext_consent' ) {
	throw new RuntimeException( 'Plaintext offsite upload was queued without explicit consent.' );
}
$local_upload = Trasloco_Job_Worker::enqueue_offsite_upload( 'fixture.wpress.enc', false );
if ( ! is_wp_error( $local_upload ) ||
	$local_upload->get_error_code() !== 'trasloco_storage_not_resumable' ) {
	throw new RuntimeException( 'A non-resumable storage provider accepted a durable upload job.' );
}
$safe_upload_payload = array( 'operation' => 'upload', 'archive' => $archive, 'allow_unencrypted' => true );
$safe_upload_job = Trasloco_Job_Store::create( 'transfer', $safe_upload_payload,
	wp_generate_password( 32, false, false ) );
if ( is_wp_error( $safe_upload_job ) ||
	Trasloco_Job_Store::workload( $safe_upload_job )['payload'] !== $safe_upload_payload ) {
	throw new RuntimeException( 'Credential-free resumable upload payload was not persisted correctly.' );
}
Trasloco_Job_Store::cancel( $safe_upload_job );
$ephemeral_upload_payload = array(
	'operation' => 'upload', 'archive' => 'scheduled-fixture.wpress.enc',
	'allow_unencrypted' => false, 'delete_after_upload' => true,
);
$ephemeral_upload_job = Trasloco_Job_Store::create( 'transfer', $ephemeral_upload_payload,
	wp_generate_password( 32, false, false ) );
if ( is_wp_error( $ephemeral_upload_job ) ||
	Trasloco_Job_Store::workload( $ephemeral_upload_job )['payload'] !== $ephemeral_upload_payload ) {
	throw new RuntimeException( 'Encrypted scheduled upload cleanup flag was not persisted safely.' );
}
Trasloco_Job_Store::cancel( $ephemeral_upload_job );
$unsafe_ephemeral = Trasloco_Job_Store::create( 'transfer', array(
	'operation' => 'upload', 'archive' => 'fixture.wpress',
	'allow_unencrypted' => true, 'delete_after_upload' => true,
), wp_generate_password( 32, false, false ) );
if ( ! is_wp_error( $unsafe_ephemeral ) ) {
	throw new RuntimeException( 'Plaintext upload accepted the ephemeral cleanup flag.' );
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

$private_checkpoint = array(
	'phase' => 'uploading',
	'provider_state' => array( 'provider' => 's3-multipart-v1', 'upload_id' => 'fixture-upload-capability' ),
);
$wpdb->update( Trasloco_Job_Store::table(), array( 'checkpoint' => wp_json_encode( $private_checkpoint ) ),
	array( 'id' => $job_id ), array( '%s' ), array( '%d' ) );
$public_job = Trasloco_Job_Store::get( $job_id );
$internal_job = Trasloco_Job_Store::get_internal( $job_id );
if ( isset( $public_job['checkpoint']['provider_state'] ) ||
	! isset( $internal_job['checkpoint']['provider_state']['upload_id'] ) ||
	$internal_job['checkpoint']['provider_state']['upload_id'] !== 'fixture-upload-capability' ) {
	throw new RuntimeException( 'Provider upload checkpoint was exposed in public job status or lost for workers.' );
}

echo "Persistent offsite download job passed.\n";
