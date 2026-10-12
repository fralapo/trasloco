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
$WP eval 'if (function_exists("wp_get_ability")) { echo "Abilities init action: " . did_action("wp_abilities_api_init") . PHP_EOL; echo "Registered: " . (wp_get_ability("trasloco/site-readiness") ? "yes" : "no") . PHP_EOL; echo "Hook callback: " . (has_action("wp_abilities_api_init", array("Trasloco_API","register_abilities")) ? "yes" : "no") . PHP_EOL; if (!wp_get_ability("trasloco/site-readiness")) { Trasloco_API::register_abilities(); echo "After explicit registration: " . (wp_get_ability("trasloco/site-readiness") ? "yes" : "no") . PHP_EOL; } }' --path="$ROOT/source"

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
cp "$ARCHIVE.manifest.json" "$ROOT/target/wp-content/trasloco-backups/"
$WP trasloco verify "$(basename "$ARCHIVE")" --path="$ROOT/target"
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
