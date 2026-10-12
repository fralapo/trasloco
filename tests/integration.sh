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
test -s "$ARCHIVE.manifest.json"
mkdir -p "$ROOT/target/wp-content/trasloco-backups"
cp "$ARCHIVE" "$ROOT/target/wp-content/trasloco-backups/"
$WP trasloco restore "$(basename "$ARCHIVE")" --path="$ROOT/target"

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
echo "WordPress backup + database + users + media migration passed."
