#!/usr/bin/env bash
set -Eeuo pipefail
export WP_CLI_ALLOW_ROOT=1
WP="php /tmp/wp"
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT

mysql --protocol=TCP -h 127.0.0.1 -u root -proot -e "CREATE DATABASE trasloco_source; CREATE DATABASE trasloco_target;"
for ROLE in source target; do
  SITE="$ROOT/$ROLE"
  $WP core download --path="$SITE" --skip-content
  mkdir -p "$SITE/wp-content/plugins"
  cp -R "$GITHUB_WORKSPACE" "$SITE/wp-content/plugins/trasloco"
  $WP config create --path="$SITE" --dbname="trasloco_$ROLE" --dbuser=root --dbpass=root --dbhost=127.0.0.1:3306 --skip-check
  if [ "$ROLE" = source ]; then
    $WP core install --path="$SITE" --url=http://old.example.test --title=Original --admin_user=oldadmin --admin_password=testpassword --admin_email=old@example.test
  else
    $WP core install --path="$SITE" --url=http://new.example.test --title=Destination --admin_user=newadmin --admin_password=testpassword --admin_email=new@example.test
  fi
  $WP plugin activate trasloco --path="$SITE"
  echo "$ROLE home before migration: $($WP option get home --path="$SITE")"
done
EXPECTED_HOME=$($WP option get home --path="$ROOT/target")
EXPECTED_SITE=$($WP option get siteurl --path="$ROOT/target")

$WP post create --path="$ROOT/source" --post_type=post --post_status=publish --post_title="Trasloco fixture: source post" --post_content="Migration round-trip verified"
mkdir -p "$ROOT/source/wp-content/uploads/test-fixtures"
printf 'media is preserved\n' > "$ROOT/source/wp-content/uploads/test-fixtures/fixture.txt"
$WP trasloco backup --path="$ROOT/source"
ARCHIVE=$(find "$ROOT/source/wp-content/trasloco-backups" -maxdepth 1 -type f -name '*.wpress' -print -quit)
if [ ! -s "$ARCHIVE" ]; then
  echo "Backup archive missing" >&2
  exit 1
fi
$WP trasloco verify "$(basename "$ARCHIVE")" --path="$ROOT/source"
# Rescue extraction must actually work independently from WordPress/MySQL,
# using only PHP CLI and the untouched .wpress bytes.
RESCUE_REPORT=$(php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$ARCHIVE")
RESCUE_DIR="$(printf '%s' "$RESCUE_REPORT" | head -n 1 | sed 's/^Recovery metadata saved in: //')"
test -n "$RESCUE_DIR"
test -s "$RESCUE_DIR/database.sql"
test -s "$RESCUE_DIR/package.json"
chmod -R u+rwX "$RESCUE_DIR"
rm -rf -- "$RESCUE_DIR"
$WP eval '
require_once TRASLOCO_LIB_PATH . "/vendor/core/archiver/class-trasloco-integrity.php";
$files = (new Trasloco_Backups())->get_files();
$path = trasloco_backup_path( array( "archive" => basename( $files[0]["filename"] ) ) );
$manifest = Trasloco_Integrity::chunked_manifest( $path );
if ( ! is_array( $manifest ) || $manifest["manifest_version"] !== 2 ||
     count( $manifest["chunks"] ) < 1 ) {
    throw new Exception( "New backups must publish resumable v2 chunk manifests" );
}
' --path="$ROOT/source"
EXTRACT_REPORT=$($WP trasloco extract-files "$(basename "$ARCHIVE")" "uploads/test-fixtures/fixture.txt" --path="$ROOT/source")
EXTRACT_DIR="${EXTRACT_REPORT##*Files extracted to: }"
test -f "$EXTRACT_DIR/uploads/test-fixtures/fixture.txt"
grep -Fx "media is preserved" "$EXTRACT_DIR/uploads/test-fixtures/fixture.txt"
rm -rf -- "$EXTRACT_DIR"
if $WP eval 'exit( ! function_exists( "sodium_crypto_secretstream_xchacha20poly1305_init_push" ) );' --path="$ROOT/source"; then
  TRASLOCO_ARCHIVE_PASSPHRASE='integration-crypt-password-2026' $WP trasloco encrypt "$(basename "$ARCHIVE")" --path="$ROOT/source"
  test -s "$ARCHIVE.enc"
  TRASLOCO_ARCHIVE_PASSPHRASE='integration-crypt-password-2026' $WP trasloco decrypt "$(basename "$ARCHIVE").enc" --path="$ROOT/source"
  RESTORED="${ARCHIVE%.wpress}-decrypted.wpress"
  cmp -s "$ARCHIVE" "$RESTORED"
  $WP trasloco verify "$(basename "$RESTORED")" --path="$ROOT/source"
fi
test -s "$ARCHIVE.manifest.json"
mkdir -p "$ROOT/target/wp-content/trasloco-backups"
cp "$ARCHIVE" "$ROOT/target/wp-content/trasloco-backups/"
cp "$ARCHIVE.manifest.json" "$ROOT/target/wp-content/trasloco-backups/"
# A damaged local backup with a sidecar must not enter destructive restore.
BAD_DIR="$ROOT/target/wp-content/trasloco-backups"
cp "$ARCHIVE" "$BAD_DIR/corrupted.wpress"
cp "$ARCHIVE.manifest.json" "$BAD_DIR/corrupted.wpress.manifest.json"
printf 'X' | dd of="$BAD_DIR/corrupted.wpress" bs=1 seek=5000 count=1 conv=notrunc status=none
if $WP trasloco restore corrupted.wpress --path="$ROOT/target"; then
  echo "ERROR: corrupted backup was allowed to restore" >&2
  exit 1
fi
if [ "$($WP option get home --path="$ROOT/target")" != "$EXPECTED_HOME" ]; then
  echo "ERROR: corrupted restore modified the destination" >&2
  exit 1
fi
rm "$BAD_DIR/corrupted.wpress" "$BAD_DIR/corrupted.wpress.manifest.json"
$WP trasloco restore "$(basename "$ARCHIVE")" --path="$ROOT/target"
RECOVERY=$(find "$ROOT/target/wp-content/trasloco-backups" -maxdepth 1 -type f -name '*.wpress' ! -name "$(basename "$ARCHIVE")" -print -quit)
test -n "$RECOVERY"
test -s "$RECOVERY.manifest.json"
[[ "$(basename "$RECOVERY")" == recovery-*.wpress ]]
$WP trasloco verify "$(basename "$RECOVERY")" --path="$ROOT/target"

echo "Destination tables:"
$WP db tables --path="$ROOT/target" || true
HOME_URL=$($WP option get home --path="$ROOT/target" || true)
echo "Destination home URL: $HOME_URL"
echo "Expected destination home URL: $EXPECTED_HOME"
echo "Expected destination siteurl: $EXPECTED_SITE"
echo "Actual destination siteurl: $($WP option get siteurl --path="$ROOT/target")"
if [ "$HOME_URL" != "$EXPECTED_HOME" ]; then
  echo "ERROR: destination URL was not preserved" >&2
  exit 1
fi
if ! $WP post list --path="$ROOT/target" --post_type=post --field=post_title | grep -F "Trasloco fixture: source post"; then
  echo "ERROR: source post missing from destination" >&2
  exit 1
fi
if ! $WP user get oldadmin --path="$ROOT/target" --field=user_login | grep -Fx oldadmin; then
  echo "ERROR: source account missing from destination" >&2
  exit 1
fi
if [ ! -f "$ROOT/target/wp-content/uploads/test-fixtures/fixture.txt" ]; then
  echo "ERROR: source media missing" >&2
  exit 1
fi
grep -Fx "media is preserved" "$ROOT/target/wp-content/uploads/test-fixtures/fixture.txt"
# The pre-import recovery file must survive automatic retention on the destination.
$WP eval '
$before = (new Trasloco_Backups())->get_files();
$protected = array_values( array_filter( $before, function ( $item ) {
    return Trasloco_Backups::is_recovery_point( $item["filename"] );
} ) );
if ( count( $protected ) < 1 ) { throw new Exception( "Recovery checkpoint not named as protected" ); }
if ( count( (new Trasloco_Backups())->get_retention_files() ) !== count( $before ) - count( $protected ) ) {
    throw new Exception( "Recovery checkpoints included in retention" );
}
$id = Trasloco_Job_Store::create( "backup", array( "retention" => 1 ), "ci-protect-recovery-0001" );
$result = Trasloco_Job_Worker::run( $id );
if ( $result["status"] !== "completed" ) {
    throw new Exception( "Retention regression backup failed: " . wp_json_encode( Trasloco_Job_Store::get( $id ) ) );
}
foreach ( $protected as $entry ) {
    if ( ! is_file( trasloco_backup_path( array( "archive" => $entry["filename"] ) ) ) ) {
        throw new Exception( "Retention deleted a recovery point" );
    }
}
if ( count( (new Trasloco_Backups())->get_retention_files() ) !== 1 ) {
    throw new Exception( "Normal backup retention not enforced" );
}
' --path="$ROOT/target"

# Authorization: regular unauthenticated visitors cannot enumerate archives.
$WP eval '
wp_set_current_user( 0 );
$deny = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/backups" ) );
if ( $deny->get_status() < 400 ) { throw new Exception( "REST endpoint accessible to anonymous user" ); }
$admins = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admins[0]->ID );
$ok = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/backups" ) );
if ( $ok->get_status() !== 200 ) { throw new Exception( "REST backup inventory refused administrator" ); }
' --path="$ROOT/target"
$WP eval '
Trasloco_Job_Store::maybe_install();
$id = Trasloco_Job_Store::create( "verify", array( "archive" => "fixture.wpress" ), "ci-checkpoint-job-0000001" );
if ( is_wp_error( $id ) || ! $id ) { throw new Exception( "Could not create durable job" ); }
$duplicate = Trasloco_Job_Store::create( "verify", array( "archive" => "fixture.wpress" ), "ci-checkpoint-job-0000001" );
if ( $id !== $duplicate ) { throw new Exception( "Idempotent create failed" ); }
$collision = Trasloco_Job_Store::create( "verify", array( "archive" => "different.wpress" ), "ci-checkpoint-job-0000001" );
if ( ! is_wp_error( $collision ) || $collision->get_error_code() !== "trasloco_idempotency_conflict" ) {
    throw new Exception( "Different backup accepted under existing idempotency key" );
}
$collision_kind = Trasloco_Job_Store::create( "backup", array( "retention" => 1 ), "ci-checkpoint-job-0000001" );
if ( ! is_wp_error( $collision_kind ) || $collision_kind->get_error_code() !== "trasloco_idempotency_conflict" ) {
    throw new Exception( "Different operation accepted under existing idempotency key" );
}
if ( ! Trasloco_Job_Store::claim( $id, "runner-000000001" ) ) { throw new Exception( "Lease failed" ); }
if ( Trasloco_Job_Store::claim( $id, "runner-000000002" ) ) { throw new Exception( "Duplicate lease was accepted" ); }
if ( Trasloco_Job_Store::checkpoint( $id, "runner-000000002", 50 ) ) { throw new Exception( "Wrong owner checkpoint accepted" ); }
$old_lease = (int) Trasloco_Job_Store::get( $id )["lease_until"];
if ( ! Trasloco_Job_Store::renew( $id, "runner-000000001", 60 ) ) {
    throw new Exception( "Same-second lease renewal was rejected" );
}
$GLOBALS["trasloco_active_backup_job"] = array( "id" => $id, "owner" => "runner-000000001", "last_renew" => 0 );
Trasloco_Status::info( "CI renew active backup lease" );
unset( $GLOBALS["trasloco_active_backup_job"] );
if ( (int) Trasloco_Job_Store::get( $id )["lease_until"] <= $old_lease ) {
    throw new Exception( "Progress heartbeat did not renew job lease" );
}
if ( ! Trasloco_Job_Store::checkpoint( $id, "runner-000000001", 50, array( "phase" => "digest" ) ) ) { throw new Exception( "Checkpoint failed" ); }
if ( ! Trasloco_Job_Store::finish( $id, "runner-000000001" ) ) { throw new Exception( "Completion failed" ); }
if ( Trasloco_Job_Store::claim( $id, "runner-000000002" ) ) { throw new Exception( "Completed job was reclaimed" ); }
if ( Trasloco_Job_Store::get( $id )["state"] !== "completed" ) { throw new Exception( "Completion status mismatch" ); }
' --path="$ROOT/target"

# REST verify jobs must reject anonymous writers and permit only administrators.
$WP eval '
wp_set_current_user( 0 );
$unauthorized = rest_do_request( new WP_REST_Request( "POST", "/trasloco/v1/backups/sample.wpress/verify" ) );
if ( $unauthorized->get_status() < 400 ) { throw new Exception( "Anonymous user queued verification" ); }
$admins = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admins[0]->ID );
$files = (new Trasloco_Backups())->get_files();
$archive = basename( $files[0]["filename"] );
$req = new WP_REST_Request( "POST", "/trasloco/v1/backups/" . $archive . "/verify" );
$queued = rest_do_request( $req );
if ( $queued->get_status() !== 202 ) { throw new Exception( "Verification job not queued: " . wp_json_encode( $queued->get_data() ) ); }
$id = $queued->get_data()["job_id"];
$done = Trasloco_Job_Worker::run( $id );
if ( $done["status"] !== "completed" || Trasloco_Job_Store::get( $id )["state"] !== "completed" ) {
    throw new Exception( "Background verification failed: " . wp_json_encode( $done ) );
}
$status = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/jobs/" . $id ) );
if ( $status->get_status() !== 200 || $status->get_data()["state"] !== "completed" ) {
    throw new Exception( "Job status not available via REST" );
}
$id2 = Trasloco_Job_Store::create( "verify", array( "archive" => $archive ), "ci-cancelled-job-00001" );
if ( ! Trasloco_Job_Store::cancel( $id2 ) || Trasloco_Job_Worker::run( $id2 )["status"] !== "not_claimed" ) {
    throw new Exception( "Cancelled job was executed" );
}
' --path="$ROOT/source"

# Stream adapter: atomic write, read and traversal/symlink rejection.
$WP eval '
$root = sys_get_temp_dir() . "/trasloco-storage-check-" . wp_generate_password( 12, false );
if ( ! mkdir( $root, 0700 ) ) { throw new Exception( "Could not create test storage" ); }
$store = new Trasloco_Local_Storage( $root );
$in = fopen( "php://temp", "w+b" );
fwrite( $in, "binary payload" );
rewind( $in );
if ( $store->put_stream( "fixture.wpress", $in ) !== strlen( "binary payload" ) ) { throw new Exception( "Storage write failed" ); }
fclose( $in );
$out = $store->open_read( "fixture.wpress" );
if ( stream_get_contents( $out ) !== "binary payload" ) { throw new Exception( "Storage read failed" ); }
fclose( $out );
if ( $store->enumerate() !== array( "fixture.wpress" ) ) { throw new Exception( "Storage inventory mismatch" ); }
try {
    $store->exists( "../other.wpress" );
    throw new Exception( "Traversal was allowed" );
} catch ( InvalidArgumentException $expected ) {}
symlink( $root . "/fixture.wpress", $root . "/alias.wpress" );
try {
    $store->open_read( "alias.wpress" );
    throw new Exception( "Symlink was allowed" );
} catch ( InvalidArgumentException $expected ) {}
if ( ! $store->delete( "fixture.wpress" ) || $store->exists( "fixture.wpress" ) ) { throw new Exception( "Delete failed" ); }
unlink( $root . "/alias.wpress" );
rmdir( $root );
' --path="$ROOT/source"

$WP eval '
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "daily", "keep" => 2 ) );
wp_clear_scheduled_hook( Trasloco_Scheduled_Backups::HOOK );
Trasloco_Scheduled_Backups::sync();
if ( ! wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ) ) { throw new Exception( "Schedule was not registered" ); }
if ( wp_get_schedule( Trasloco_Scheduled_Backups::HOOK ) !== "daily" ) { throw new Exception( "Initial recurrence incorrect" ); }
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "weekly", "keep" => 2 ) );
Trasloco_Scheduled_Backups::sync();
if ( wp_get_schedule( Trasloco_Scheduled_Backups::HOOK ) !== "weekly" ) { throw new Exception( "Old recurrence was not replaced" ); }
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "daily", "keep" => 2 ) );
Trasloco_Scheduled_Backups::sync();
if ( wp_get_schedule( Trasloco_Scheduled_Backups::HOOK ) !== "daily" ) { throw new Exception( "Recurrence was not restored to daily" ); }
$id = Trasloco_Scheduled_Backups::enqueue();
if ( ! is_int( $id ) || $id < 1 ) { throw new Exception( "Schedule failed to enqueue" ); }
$result = Trasloco_Job_Worker::run( $id );
if ( $result["status"] !== "completed" ) { throw new Exception( "Scheduled export failed: " . wp_json_encode( Trasloco_Job_Store::get( $id ) ) ); }
$archive = trasloco_backup_path( array( "archive" => $result["archive"] ) );
if ( Trasloco_Integrity::verify( $archive )["status"] !== "verified" ) { throw new Exception( "Scheduled archive is corrupt" ); }
if ( count( (new Trasloco_Backups())->get_files() ) > 2 ) { throw new Exception( "Retention failed" ); }
// Calendar-month scheduling follows WordPress timezone and is one-shot.
update_option( "timezone_string", "Europe/Rome" );
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "monthly", "keep" => 2 ) );
Trasloco_Scheduled_Backups::sync();
$expected = (new DateTimeImmutable( "first day of next month 03:00:00", wp_timezone() ))->getTimestamp();
if ( wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ) !== $expected ||
    wp_get_schedule( Trasloco_Scheduled_Backups::HOOK ) !== false ) {
    throw new Exception( "Monthly cron did not follow the site timezone" );
}
if ( Trasloco_Scheduled_Backups::enqueue() !== Trasloco_Scheduled_Backups::enqueue() ) {
    throw new Exception( "Duplicate monthly job was not deduplicated" );
}
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "off", "keep" => 2 ) );
Trasloco_Scheduled_Backups::sync();
if ( wp_next_scheduled( Trasloco_Scheduled_Backups::HOOK ) ) { throw new Exception( "Disabled schedule still active" ); }
' --path="$ROOT/source"

# Upgrade safety: opt-in and no recursive updates. A valid checkpoint passes.
$WP eval '
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "off", "keep" => 2, "before_updates" => true ) );
$before = count( (new Trasloco_Backups())->get_files() );
$result = apply_filters( "upgrader_pre_install", true, array( "action" => "update", "type" => "plugin" ) );
if ( $result !== true ) { throw new Exception( "Update blocked despite successful safety copy" ); }
$after = count( (new Trasloco_Backups())->get_files() );
if ( $after <= $before ) { throw new Exception( "No pre-update checkpoint was created" ); }
update_option( Trasloco_Scheduled_Backups::OPTION, array( "frequency" => "off", "keep" => 2, "before_updates" => false ) );
' --path="$ROOT/source"

# The agency health endpoint is private and does not disclose internal paths.
$WP eval '
wp_set_current_user( 0 );
$unauthorized = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/health" ) );
if ( $unauthorized->get_status() < 400 ) { throw new Exception( "Anonymous site health access permitted" ); }
$users = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $users[0]->ID );
$ok = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/health" ) );
$data = $ok->get_data();
if ( $ok->get_status() !== 200 || ! array_key_exists( "latest_backup", $data ) ) { throw new Exception( "Health API failed" ); }
if ( strpos( wp_json_encode( $data ), ABSPATH ) !== false ) { throw new Exception( "Server paths disclosed in health API" ); }
' --path="$ROOT/source"

# An existing export must prevent a second server-side backup from racing.
$WP eval '
$path = TRASLOCO_BACKUPS_PATH . "/.trasloco-export.lock";
$holder = fopen( $path, "c+" );
if ( ! $holder || ! flock( $holder, LOCK_EX | LOCK_NB ) ) {
    throw new Exception( "Could not acquire fixture export lock" );
}
$id = Trasloco_Job_Store::create( "backup", array( "retention" => 1 ), "ci-overlap-blocked-00001" );
$result = Trasloco_Job_Worker::run( $id );
if ( $result["status"] !== "failed" ) {
    throw new Exception( "Concurrent server-side backup was allowed" );
}
$stored = Trasloco_Job_Store::get( $id );
if ( $stored["state"] !== "failed" ) {
    throw new Exception( "Concurrent attempt did not fail safely" );
}
flock( $holder, LOCK_UN );
fclose( $holder );
' --path="$ROOT/source"

# Failed scheduled jobs fire an alert hook without deleting good backups.
$WP eval '
$GLOBALS["tr_fail_notified"] = false;
add_action( "trasloco_scheduled_backup_failed", function ( $job_id ) {
    $GLOBALS["tr_fail_notified"] = $job_id > 0;
}, 10, 1 );
$id = Trasloco_Job_Store::create( "backup", array( "retention" => 0 ), "ci-failure-notification-job-1234" );
$result = Trasloco_Job_Worker::run( $id );
if ( $result["status"] !== "failed" || ! $GLOBALS["tr_fail_notified"] ) {
    throw new Exception( "Backup failure alert was not emitted" );
}
' --path="$ROOT/source"

# Non-destructive preview reports inventory without modifying the site.
$WP eval '
$backups = (new Trasloco_Backups())->get_files();
$file = basename( $backups[0]["filename"] );
$report = Trasloco_Archive_Inspector::inspect( trasloco_backup_path( array( "archive" => $file ) ), 30 );
if ( $report["files_in_archive"] < 3 || $report["uncompressed_bytes"] <= 0 ) {
    throw new Exception( "Archive preview did not enumerate files" );
}
if ( strpos( $report["warning"], "does not compare" ) === false ) {
    throw new Exception( "Missing explicit preview limitation" );
}
wp_set_current_user( 0 );
$unauth = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/backups/" . $file . "/preview" ) );
if ( $unauth->get_status() < 400 ) { throw new Exception( "Anonymous preview was allowed" ); }
$admins = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admins[0]->ID );
$resp = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/backups/" . $file . "/preview" ) );
if ( $resp->get_status() !== 200 ) { throw new Exception( "Authenticated archive preview failed" ); }
' --path="$ROOT/source"

# Reconcile only idempotent verification jobs; do not repeat backups.
$WP eval '
$id = Trasloco_Job_Store::create( "verify", array( "archive" => "missing.wpress" ), "ci-reconcile-pending-verify-0001" );
if ( is_wp_error( $id ) ) { throw new Exception( "Cannot create test verification" ); }
if ( ! in_array( $id, Trasloco_Job_Store::recoverable_verifications(), true ) ) { throw new Exception( "Pending verification not recoverable" ); }
$failed = Trasloco_Job_Worker::run( $id );
if ( $failed["status"] !== "failed" ) { throw new Exception( "Missing archive verification did not fail" ); }
if ( ! Trasloco_Job_Store::retry_verification( $id ) ) { throw new Exception( "Verification retry not supported" ); }
$recent = Trasloco_Job_Store::recent();
if ( empty( $recent ) || ! isset( $recent[0]["id"] ) ) { throw new Exception( "Recent job listing failed" ); }
' --path="$ROOT/source"


# V2 verification yields durable checkpoints in bounded two-chunk batches.
$WP eval '
require_once TRASLOCO_LIB_PATH . "/vendor/core/archiver/class-trasloco-integrity.php";
$path = TRASLOCO_BACKUPS_PATH . "/ci-chunked-verification.wpress";
$handle = fopen( $path, "wb" );
for ( $i = 0; $i < 5; $i++ ) {
    fwrite( $handle, str_repeat( chr( 65 + $i ), Trasloco_Integrity::CHUNK_BYTES ) );
}
fclose( $handle );
$manifest = Trasloco_Integrity::create_chunked( $path );
$id = Trasloco_Job_Store::create( "verify", array( "archive" => basename( $path ) ), "ci-v2-resumable-verify-0000001" );
if ( is_wp_error( $id ) ) { throw new Exception( "Cannot start resumable verification" ); }
$a = Trasloco_Job_Worker::run( $id );
$first = Trasloco_Job_Store::get( $id );
if ( $a["status"] !== "pending" || $first["checkpoint"]["next_chunk"] !== 2 ||
     $first["progress"] < 1 || $first["state"] !== "pending" || $first["attempts"] !== 0 ) {
    throw new Exception( "First chunk checkpoint failed: " . wp_json_encode( $first ) );
}
$b = Trasloco_Job_Worker::run( $id );
$second = Trasloco_Job_Store::get( $id );
if ( $b["status"] !== "pending" || $second["checkpoint"]["next_chunk"] !== 4 ) {
    throw new Exception( "Second chunk checkpoint failed" );
}
$c = Trasloco_Job_Worker::run( $id );
if ( $c["status"] !== "completed" || Trasloco_Job_Store::get( $id )["state"] !== "completed" ) {
    throw new Exception( "Resumable verification did not complete" );
}
// Tamper a valid archive, and rewrite only its per-chunk digest in an
// otherwise well-formed manifest. Global checksum must still reject it.
$original = file_get_contents( $path, false, null, 0, 1 );
$handle = fopen( $path, "r+b" );
fwrite( $handle, $original === "A" ? "Z" : "A" );
fclose( $handle );
$body = json_decode( file_get_contents( $manifest ), true );
$body["chunks"][0] = hash_file( "sha256", $path, false ) === false ? "" : hash( "sha256", substr( file_get_contents( $path ), 0, Trasloco_Integrity::CHUNK_BYTES ) );
file_put_contents( $manifest, wp_json_encode( $body ) );
$bad = Trasloco_Job_Store::create( "verify", array( "archive" => basename( $path ) ), "ci-v2-check-global-hash-000001" );
if ( is_wp_error( $bad ) ) { throw new Exception( "Cannot create global-hash regression job" ); }
for ( $step = 0; $step < 4; $step++ ) {
    $outcome = Trasloco_Job_Worker::run( $bad );
    if ( $outcome["status"] !== "pending" ) {
        break;
    }
}
if ( Trasloco_Job_Store::get( $bad )["state"] !== "failed" ) {
    throw new Exception( "Resumable worker did not reject a rewritten chunk list with stale global SHA-256" );
}
unlink( $path );
unlink( $manifest );
' --path="$ROOT/source"

# A crashed verification must not retry endlessly or block reconciliation.
$WP eval '
global $wpdb;
$id = Trasloco_Job_Store::create( "verify", array( "archive" => "offline.wpress" ), "ci-verification-retry-limit-0001" );
if ( is_wp_error( $id ) ) { throw new Exception( "Cannot create verification fixture" ); }
$table = Trasloco_Job_Store::table();
$wpdb->update( $table, array( "state" => "running", "attempts" => 3,
    "lease_until" => time() - 3600, "lease_owner" => "stale-owner-12345" ), array( "id" => $id ) );
if ( in_array( $id, Trasloco_Job_Store::recoverable_verifications( 50 ), true ) ) {
    throw new Exception( "Exhausted verification requeued" );
}
if ( Trasloco_Job_Store::claim( $id, "ci-runner-12345678" ) ) {
    throw new Exception( "Exhausted verification reclaimed" );
}
Trasloco_Job_Store::fail_exhausted_verifications();
if ( Trasloco_Job_Store::get( $id )["state"] !== "failed" ) {
    throw new Exception( "Exhausted verification not terminal" );
}
if ( Trasloco_Job_Store::retry_verification( $id ) ) {
    throw new Exception( "Exhausted verification restarted" );
}
' --path="$ROOT/source"

# Recovery rehearsal: the safety snapshot must actually restore the previous
# destination database, not merely pass a checksum.
$WP trasloco restore "$(basename "$RECOVERY")" --path="$ROOT/target"
$WP user get newadmin --path="$ROOT/target" --field=user_login | grep -Fx newadmin
if $WP post list --path="$ROOT/target" --post_type=post --field=post_title | grep -Fq "Trasloco fixture: source post"; then
  echo "ERROR: recovery point did not restore the destination database" >&2
  exit 1
fi
# Best-effort admin warning for Nginx deployments where backups are web-accessible.
$WP eval '
$users = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $users[0]->ID );
$_SERVER["SERVER_SOFTWARE"] = "nginx/1.25";
$_SERVER["DOCUMENT_ROOT"] = ABSPATH;
ob_start();
$controller = new Trasloco_Main_Controller();
$controller->nginx_backup_security_notice();
$warning = ob_get_clean();
if ( strpos( $warning, "Nginx" ) === false || strpos( $warning, "TRASLOCO_BACKUPS_DIR" ) === false ) {
    throw new Exception( "Missing Nginx backups security warning" );
}
' --path="$ROOT/source"
# Admin form verification must be tested while the plugin is active.
$WP eval '
$admins = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admins[0]->ID );
$files = (new Trasloco_Backups())->get_files();
$_POST["archive"] = basename( $files[0]["filename"] );
$_POST["trasloco_verify_nonce"] = wp_create_nonce( "trasloco_verify_archive" );
// WP-CLI does not merge manually assigned $_POST into $_REQUEST.
$_REQUEST["trasloco_verify_nonce"] = $_POST["trasloco_verify_nonce"];
$_SERVER["REQUEST_METHOD"] = "POST";
Trasloco_Job_Worker::submit_verification();
' --path="$ROOT/source"

$WP eval '
global $wpdb;
$table = Trasloco_Job_Store::table();
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE kind = %s", "verify" ) );
if ( $count < 1 ) { throw new Exception( "Admin POST failed to create verification job" ); }
' --path="$ROOT/source"

# Plugin deactivation removes recurring timers and queued workers only.
$WP plugin deactivate trasloco --path="$ROOT/source"
$WP eval '
foreach ( array( "trasloco_scheduled_backup_tick", "trasloco_reconcile_jobs" ) as $hook ) {
    if ( wp_next_scheduled( $hook ) ) {
        throw new Exception( "Deactivated plugin left an active cron event: " . $hook );
    }
}
foreach ( (array) _get_cron_array() as $events ) {
    if ( isset( $events["trasloco_run_job"] ) ) {
        throw new Exception( "Deactivated plugin left worker events queued" );
    }
}
' --path="$ROOT/source"

# Diagnostics are private, bounded and do not expose server filesystem paths.
$WP eval '
wp_set_current_user( 0 );
$deny = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/preflight" ) );
if ( $deny->get_status() < 400 ) { throw new Exception( "Preflight exposed to anonymous visitor" ); }
$admins = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admins[0]->ID );
$response = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/preflight" ) );
$report = $response->get_data();
if ( $response->get_status() !== 200 || ! isset( $report["backup_directory"] ) ||
    ! isset( $report["backup_web_access"] ) || ! is_array( $report["issues"] ) ) {
    throw new Exception( "Administrator preflight diagnostics unavailable" );
}
if ( strpos( wp_json_encode( $report ), ABSPATH ) !== false ) {
    throw new Exception( "Preflight leaked an absolute server path" );
}
' --path="$ROOT/target"
$WP trasloco doctor --format=json --path="$ROOT/target" | grep -F '"backup_directory"'
echo "WordPress migration, recovery rehearsal, jobs and integrity checks passed."
