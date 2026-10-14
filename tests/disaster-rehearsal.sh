#!/usr/bin/env bash
# Destructive only to a newly allocated site/database under the CI temp root.
# A real restore is exercised without the original site's WordPress bootstrap.
set -Eeuo pipefail
export WP_CLI_ALLOW_ROOT=1
ARCHIVE="$1"
ROOT="$2"
WP="php /tmp/wp"
TRASLOCO_TEST_DB_HOST="${TRASLOCO_TEST_DB_HOST:-127.0.0.1}"
TRASLOCO_TEST_DB_PORT="${TRASLOCO_TEST_DB_PORT:-3306}"
TRASLOCO_TEST_DB_USER="${TRASLOCO_TEST_DB_USER:-root}"
TRASLOCO_TEST_DB_PASSWORD="${TRASLOCO_TEST_DB_PASSWORD:-root}"
MYSQL=(mysql --protocol=TCP -h "$TRASLOCO_TEST_DB_HOST" -P "$TRASLOCO_TEST_DB_PORT" \
  -u "$TRASLOCO_TEST_DB_USER" "-p$TRASLOCO_TEST_DB_PASSWORD")
if [ ! -s "$ARCHIVE" ] || [ ! -s "$ARCHIVE.manifest.json" ]; then
  echo "Disaster rehearsal needs a verified backup and manifest" >&2
  exit 1
fi
"${MYSQL[@]}" -e "CREATE DATABASE trasloco_disaster_rehearsal;"
SITE="$ROOT/disaster-rehearsal"
if [ -n "${TRASLOCO_TEST_WORDPRESS_VERSION:-}" ]; then
  $WP core download --path="$SITE" --skip-content --version="$TRASLOCO_TEST_WORDPRESS_VERSION"
else
  $WP core download --path="$SITE" --skip-content
fi
mkdir -p "$SITE/wp-content/plugins"
cp -R "$GITHUB_WORKSPACE" "$SITE/wp-content/plugins/trasloco"
$WP config create --path="$SITE" --dbname=trasloco_disaster_rehearsal \
  --dbuser="$TRASLOCO_TEST_DB_USER" --dbpass="$TRASLOCO_TEST_DB_PASSWORD" \
  --dbhost="$TRASLOCO_TEST_DB_HOST:$TRASLOCO_TEST_DB_PORT" --skip-check
$WP core install --path="$SITE" --url=http://rescue.example.test \
  --title="Clean disaster recovery installation" --admin_user=temporary_rescue_admin \
  --admin_password=temporary_test_password --admin_email=rescue@example.test
$WP plugin activate trasloco --path="$SITE"
EXPECTED_HOME="$($WP option get home --path="$SITE")"
EXPECTED_SITE="$($WP option get siteurl --path="$SITE")"
echo "Fresh disaster site home: $EXPECTED_HOME"
echo "Fresh disaster site URL: $EXPECTED_SITE"
mkdir -p "$SITE/wp-content/trasloco-backups"
cp "$ARCHIVE" "$ARCHIVE.manifest.json" "$SITE/wp-content/trasloco-backups/"
NAME="$(basename "$ARCHIVE")"
$WP trasloco verify "$NAME" --path="$SITE"
$WP trasloco restore "$NAME" --path="$SITE"
RESTORED_HOME="$($WP option get home --path="$SITE")"
RESTORED_SITE="$($WP option get siteurl --path="$SITE")"
echo "Disaster restore home: $RESTORED_HOME"
echo "Disaster restore siteurl: $RESTORED_SITE"
if [ "$RESTORED_HOME" != "$EXPECTED_HOME" ] || [ "$RESTORED_SITE" != "$EXPECTED_SITE" ]; then
  echo "ERROR: disaster restore did not preserve the fresh destination URLs" >&2
  exit 1
fi
$WP user get oldadmin --path="$SITE" --field=user_login | grep -Fx oldadmin
$WP post list --path="$SITE" --post_type=post --field=post_title | \
  grep -F "Trasloco fixture: source post"
test -f "$SITE/wp-content/uploads/test-fixtures/fixture.txt"
grep -Fx "media is preserved" "$SITE/wp-content/uploads/test-fixtures/fixture.txt"
$WP db check --path="$SITE"
$WP core is-installed --path="$SITE"
echo "PASS: independently provisioned WordPress/MySQL restored archive with users, posts and media"
