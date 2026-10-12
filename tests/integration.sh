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
$WP trasloco restore "$(basename "$ARCHIVE")" --path="$ROOT/target"
RECOVERY=$(find "$ROOT/target/wp-content/trasloco-backups" -maxdepth 1 -type f -name '*.wpress' ! -name "$(basename "$ARCHIVE")" -print -quit)
test -n "$RECOVERY"
test -s "$RECOVERY.manifest.json"
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
if ( ! Trasloco_Job_Store::claim( $id, "runner-000000001" ) ) { throw new Exception( "Lease failed" ); }
if ( Trasloco_Job_Store::claim( $id, "runner-000000002" ) ) { throw new Exception( "Duplicate lease was accepted" ); }
if ( Trasloco_Job_Store::checkpoint( $id, "runner-000000002", 50 ) ) { throw new Exception( "Wrong owner checkpoint accepted" ); }
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
$id = Trasloco_Scheduled_Backups::enqueue();
if ( ! is_int( $id ) || $id < 1 ) { throw new Exception( "Schedule failed to enqueue" ); }
$result = Trasloco_Job_Worker::run( $id );
if ( $result["status"] !== "completed" ) { throw new Exception( "Scheduled export failed: " . wp_json_encode( Trasloco_Job_Store::get( $id ) ) ); }
$archive = trasloco_backup_path( array( "archive" => $result["archive"] ) );
if ( Trasloco_Integrity::verify( $archive )["status"] !== "verified" ) { throw new Exception( "Scheduled archive is corrupt" ); }
if ( count( (new Trasloco_Backups())->get_files() ) > 2 ) { throw new Exception( "Retention failed" ); }
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
echo "WordPress backup + database + users + media migration passed."
