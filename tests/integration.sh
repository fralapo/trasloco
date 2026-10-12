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

$WP eval '$user = get_user_by("login", "oldadmin"); wp_set_current_user($user->ID); $result = rest_do_request(new WP_REST_Request("GET", "/trasloco/v1/health")); if ($result->get_status() !== 200 || empty($result->get_data()["plugin_version"])) { throw new RuntimeException("Authenticated REST health failed"); } wp_set_current_user(0); $denied = rest_do_request(new WP_REST_Request("GET", "/trasloco/v1/health")); if ($denied->get_status() < 400) { throw new RuntimeException("REST health exposed to anonymous callers"); }' --path="$ROOT/source"
$WP eval 'if (function_exists("wp_get_ability")) { $ability = wp_get_ability("trasloco/site-readiness"); if (!$ability) { throw new RuntimeException("Abilities not registered on initialization"); } $user = get_user_by("login","oldadmin"); wp_set_current_user($user->ID); $result = $ability->execute(); if (is_wp_error($result) || empty($result["plugin_version"])) { throw new RuntimeException("Abilities execution failed for administrator"); } }' --path="$ROOT/source"

$WP eval 'if ( !class_exists("Trasloco_Agency") || count(Trasloco_Agency::sites()) !== 0 ) { throw new RuntimeException("Agency dashboard not safely opt-in"); }' --path="$ROOT/source"

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
$WP eval '$archive = basename(glob(WP_CONTENT_DIR . "/trasloco-backups/*.wpress")[0]); $user = get_user_by("login","oldadmin"); wp_set_current_user($user->ID); $res = rest_do_request(new WP_REST_Request("GET","/trasloco/v1/backups/".$archive."/manifest")); if ($res->get_status() !== 200 || empty($res->get_data()["sha256"])) { throw new RuntimeException("Peer manifest failed"); } $request = new WP_REST_Request("GET","/trasloco/v1/backups/".$archive."/chunk"); $request->set_param("offset",0); $response = rest_do_request($request); $chunk = $response->get_data(); if ($response->get_status() !== 200 || !hash_equals(hash("sha256",base64_decode($chunk["data"])), $chunk["sha256"])) { throw new RuntimeException("Peer fragment failed"); } wp_set_current_user(0); if (rest_do_request(new WP_REST_Request("GET","/trasloco/v1/backups/".$archive."/manifest"))->get_status() < 400) { throw new RuntimeException("Anonymous transfer permitted"); }' --path="$ROOT/source"
test -s "$ARCHIVE.manifest.json"
$WP eval-file "$ROOT/source/wp-content/plugins/trasloco/tests/peer-pull-smoke.php" --path="$ROOT/source"
mkdir -p "$ROOT/offsite"
$WP config set TRASLOCO_REMOTE_DRIVER local --type=constant --path="$ROOT/source"
$WP config set TRASLOCO_REMOTE_DIRECTORY "$ROOT/offsite" --type=constant --path="$ROOT/source"
export TRASLOCO_BACKUP_PASSPHRASE='integration fixture has a long secret phrase'
$WP trasloco encrypt "$(basename "$ARCHIVE")" --path="$ROOT/source"
$WP trasloco offsite upload "$(basename "$ARCHIVE").enc" --path="$ROOT/source"
test -s "$ROOT/offsite/$(basename "$ARCHIVE").enc"
mkdir -p "$ROOT/target/wp-content/trasloco-backups"
cp "$ARCHIVE" "$ROOT/target/wp-content/trasloco-backups/"
cp "$ARCHIVE.manifest.json" "$ROOT/target/wp-content/trasloco-backups/"
$WP trasloco verify "$(basename "$ARCHIVE")" --path="$ROOT/target"
$WP trasloco preview "$(basename "$ARCHIVE")" --path="$ROOT/target"
$WP eval '$user = get_user_by("login", "newadmin"); wp_set_current_user($user->ID); $archive = basename(glob(WP_CONTENT_DIR . "/trasloco-backups/*.wpress")[0]); $req = new WP_REST_Request("POST", "/trasloco/v1/backups/" . $archive . "/preview"); $res = rest_do_request($req); if ($res->get_status() !== 200 || !isset($res->get_data()["source_theme"])) { throw new RuntimeException("REST preview failed"); } wp_set_current_user(0); $denied = rest_do_request($req); if ($denied->get_status() < 400) { throw new RuntimeException("REST preview exposed to anonymous visitors"); }' --path="$ROOT/target"
$WP trasloco restore "$(basename "$ARCHIVE")" --path="$ROOT/target"
# A second, independently verified archive of the ORIGINAL destination must
# survive the destructive restore, even though WordPress users were replaced.
RECOVERY=$(find "$ROOT/target/wp-content/trasloco-backups" -maxdepth 1 -type f -name '*.wpress' ! -name "$(basename "$ARCHIVE")" -print -quit)
test -n "$RECOVERY" && test -s "$RECOVERY.manifest.json"
$WP trasloco verify "$(basename "$RECOVERY")" --path="$ROOT/target"
$WP config set TRASLOCO_REMOTE_DRIVER local --type=constant --path="$ROOT/target"
$WP config set TRASLOCO_REMOTE_DIRECTORY "$ROOT/offsite" --type=constant --path="$ROOT/target"
$WP trasloco offsite download "$(basename "$ARCHIVE").enc" --path="$ROOT/target"
test -s "$ROOT/target/wp-content/trasloco-backups/$(basename "$ARCHIVE").enc"

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
# Selectively restore a deleted media file without touching tables.
rm "$ROOT/target/wp-content/uploads/test-fixtures/fixture.txt"
$WP trasloco restore-media "$(basename "$ARCHIVE")" test-fixtures/fixture.txt --path="$ROOT/target"
grep -Fx "media is preserved" "$ROOT/target/wp-content/uploads/test-fixtures/fixture.txt"
if $WP trasloco restore-media "$(basename "$ARCHIVE")" test-fixtures/fixture.txt --path="$ROOT/target"; then
  echo "ERROR: selective restore overwrote an existing destination" >&2
  exit 1
fi
# Reuse the existing full-export stages in a durable, background-style job.
$WP eval 'if ( ! Trasloco_Background::start() ) { throw new RuntimeException("Could not queue backup"); }' --path="$ROOT/source"
for ATTEMPT in $(seq 1 65); do
  $WP eval 'Trasloco_Background::tick();' --path="$ROOT/source"
  if $WP eval '$last = get_option(Trasloco_Background::LAST); if (!empty($last) && $last["state"] === "completed") { exit(0); } exit(1);' --path="$ROOT/source"; then
    break
  fi
done
$WP eval '$last = get_option(Trasloco_Background::LAST); if (empty($last) || $last["state"] !== "completed") { throw new RuntimeException("Background backup failed: " . json_encode($last)); } $result = Trasloco_Manifest::inspect($last["archive"]); if ($result["status"] !== "verified") { throw new RuntimeException("Scheduled backup verification failed"); }' --path="$ROOT/source"
echo 'corruption!' >> "$ARCHIVE"
if $WP trasloco verify "$(basename "$ARCHIVE")" --path="$ROOT/source"; then
  echo "ERROR: corrupted backup verification unexpectedly succeeded" >&2
  exit 1
fi
echo "WordPress backup + database + users + media migration passed."
