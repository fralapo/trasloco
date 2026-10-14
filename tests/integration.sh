#!/usr/bin/env bash
set -Eeuo pipefail
export WP_CLI_ALLOW_ROOT=1
WP="php /tmp/wp"
TRASLOCO_TEST_DB_HOST="${TRASLOCO_TEST_DB_HOST:-127.0.0.1}"
TRASLOCO_TEST_DB_PORT="${TRASLOCO_TEST_DB_PORT:-3306}"
TRASLOCO_TEST_DB_USER="${TRASLOCO_TEST_DB_USER:-root}"
TRASLOCO_TEST_DB_PASSWORD="${TRASLOCO_TEST_DB_PASSWORD:-root}"
export TRASLOCO_TEST_DB_HOST TRASLOCO_TEST_DB_PORT TRASLOCO_TEST_DB_USER TRASLOCO_TEST_DB_PASSWORD
MYSQL=(mysql --protocol=TCP -h "$TRASLOCO_TEST_DB_HOST" -P "$TRASLOCO_TEST_DB_PORT" \
  -u "$TRASLOCO_TEST_DB_USER" "-p$TRASLOCO_TEST_DB_PASSWORD")
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT

"${MYSQL[@]}" -e "CREATE DATABASE trasloco_source; CREATE DATABASE trasloco_target;"
for ROLE in source target; do
  SITE="$ROOT/$ROLE"
  $WP core download --path="$SITE" --skip-content
  mkdir -p "$SITE/wp-content/plugins"
  cp -R "$GITHUB_WORKSPACE" "$SITE/wp-content/plugins/trasloco"
  $WP config create --path="$SITE" --dbname="trasloco_$ROLE" --dbuser="$TRASLOCO_TEST_DB_USER" \
    --dbpass="$TRASLOCO_TEST_DB_PASSWORD" --dbhost="$TRASLOCO_TEST_DB_HOST:$TRASLOCO_TEST_DB_PORT" --skip-check
  if [ "$ROLE" = source ]; then
    $WP core install --path="$SITE" --url=http://old.example.test --title=Original --admin_user=oldadmin --admin_password=testpassword --admin_email=old@example.test
  else
    $WP core install --path="$SITE" --url=http://new.example.test --title=Destination --admin_user=newadmin --admin_password=testpassword --admin_email=new@example.test
  fi
  $WP plugin activate trasloco --path="$SITE"
  echo "$ROLE home before migration: $($WP option get home --path="$SITE")"
done
WC_ORDER_ID=""
WC_REFUND_ID=""
WC_PRODUCT_ID=""
if [ "${TRASLOCO_TEST_WOOCOMMERCE:-0}" = 1 ]; then
  TRASLOCO_TEST_WOOCOMMERCE_VERSION="${TRASLOCO_TEST_WOOCOMMERCE_VERSION:-11.2.1}"
  TRASLOCO_TEST_WOOCOMMERCE_HPOS="${TRASLOCO_TEST_WOOCOMMERCE_HPOS:-1}"
  if [ "$TRASLOCO_TEST_WOOCOMMERCE_HPOS" = 1 ]; then
    WC_ORDER_STORE=hpos
    WC_ORDER_STORE_COMMAND=enable
  elif [ "$TRASLOCO_TEST_WOOCOMMERCE_HPOS" = 0 ]; then
    WC_ORDER_STORE=legacy
    WC_ORDER_STORE_COMMAND=disable
  else
    echo "TRASLOCO_TEST_WOOCOMMERCE_HPOS must be 0 or 1" >&2
    exit 1
  fi
  for ROLE in source target; do
    $WP plugin install woocommerce --version="$TRASLOCO_TEST_WOOCOMMERCE_VERSION" \
      --activate --path="$ROOT/$ROLE"
    $WP wc hpos "$WC_ORDER_STORE_COMMAND" --path="$ROOT/$ROLE"
  done
  WC_FIXTURE=$($WP eval-file "$GITHUB_WORKSPACE/tests/woocommerce-fixture.php" seed "$WC_ORDER_STORE" --path="$ROOT/source" | tail -n 1)
  WC_ORDER_ID=$(printf '%s' "$WC_FIXTURE" | php -r '$v=json_decode(stream_get_contents(STDIN),true); if(!is_array($v)||empty($v["order_id"])) exit(1); echo (int)$v["order_id"];')
  WC_REFUND_ID=$(printf '%s' "$WC_FIXTURE" | php -r '$v=json_decode(stream_get_contents(STDIN),true); if(!is_array($v)||empty($v["refund_id"])) exit(1); echo (int)$v["refund_id"];')
  WC_PRODUCT_ID=$(printf '%s' "$WC_FIXTURE" | php -r '$v=json_decode(stream_get_contents(STDIN),true); if(!is_array($v)||empty($v["product_id"])) exit(1); echo (int)$v["product_id"];')
  $WP eval-file "$GITHUB_WORKSPACE/tests/woocommerce-fixture.php" verify \
    "$WC_ORDER_ID" "$WC_REFUND_ID" "$WC_PRODUCT_ID" "$WC_ORDER_STORE" --path="$ROOT/source"
fi
EXPECTED_HOME=$($WP option get home --path="$ROOT/target")
EXPECTED_SITE=$($WP option get siteurl --path="$ROOT/target")
# Set up an original destination file BEFORE intentionally failing SQL.
# The recovery snapshot must contain it to prove that the offline diff sees
# files lost by the partially destructive restore.
mkdir -p "$ROOT/target/wp-content/uploads/target-before-import"
printf 'target media survived independent rescue\n' > "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
cp "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt" "$ROOT/target-safety-marker.expected"


$WP post create --path="$ROOT/source" --post_type=post --post_status=publish --post_title="Trasloco fixture: source post" --post_content="Migration round-trip verified"
mkdir -p "$ROOT/source/wp-content/uploads/test-fixtures"
printf 'media is preserved\n' > "$ROOT/source/wp-content/uploads/test-fixtures/fixture.txt"
# File and directory symlinks must not pull secrets from outside wp-content.
mkdir -p "$ROOT/private-outside-wp"
printf 'TRASLOCO_OUTSIDE_SECRET_GUARD_20261012\n' > "$ROOT/private-outside-wp/private.txt"
ln -s "$ROOT/private-outside-wp/private.txt" "$ROOT/source/wp-content/uploads/private-file-link.txt"
ln -s "$ROOT/private-outside-wp" "$ROOT/source/wp-content/uploads/private-directory-link"
# Any symlink in wp-content must be reported as an incomplete backup,
# not silently skipped or dereferenced into private external data.
if $WP trasloco backup --path="$ROOT/source"; then
  echo "ERROR: backup succeeded despite a symlinked content dependency" >&2
  exit 1
fi
rm "$ROOT/source/wp-content/uploads/private-file-link.txt"
rm "$ROOT/source/wp-content/uploads/private-directory-link"
$WP trasloco backup --path="$ROOT/source"
ARCHIVE=$(find "$ROOT/source/wp-content/trasloco-backups" -maxdepth 1 -type f -name '*.wpress' -print -quit)
if grep -aFq 'TRASLOCO_OUTSIDE_SECRET_GUARD_20261012' "$ARCHIVE"; then
  echo "ERROR: backup exported data reached through an external symlink" >&2
  exit 1
fi
if [ ! -s "$ARCHIVE" ]; then
  echo "Backup archive missing" >&2
  exit 1
fi
$WP trasloco verify "$(basename "$ARCHIVE")" --path="$ROOT/source"
# Disaster rehearsal on a separately bootstrapped WordPress and fresh MySQL DB.
bash "$GITHUB_WORKSPACE/tests/disaster-rehearsal.sh" "$ARCHIVE" "$ROOT"
# Destructive-import guard: a structurally valid backup with the SQL entry
# removed MUST fail before copying target files or touching destination users.
MISSING_SQL="$ROOT/fixture-missing-database.wpress"
php "$GITHUB_WORKSPACE/tests/strip-database-entry.php" "$ARCHIVE" "$MISSING_SQL"
mkdir -p "$ROOT/target/wp-content/trasloco-backups"
cp "$MISSING_SQL" "$ROOT/target/wp-content/trasloco-backups/"
if ERROR=$($WP trasloco restore "$(basename "$MISSING_SQL")" --path="$ROOT/target" 2>&1); then
  echo "ERROR: incomplete database backup incorrectly restored" >&2
  exit 1
fi
printf '%s\n' "$ERROR" | grep -F 'requires database.sql'
test "$($WP option get home --path="$ROOT/target")" = "$EXPECTED_HOME"
$WP user get newadmin --path="$ROOT/target" --field=user_login | grep -Fx newadmin
test ! -e "$ROOT/target/wp-content/uploads/test-fixtures/fixture.txt"
# Do not let the deliberately malformed negative fixture enter recovery
# inventory: filesystem iteration order varies across MySQL/PHP CI workers.
rm -f "$ROOT/target/wp-content/trasloco-backups/$(basename "$MISSING_SQL")" "$MISSING_SQL"

# Force a REAL database execution error AFTER the source SQL DDL/DML has run.
# MySQL DDL cannot be rolled back as a single transaction. The independent
# recovery receipt must remain pending and the original destination database
# must be manually restorable from its snapshot on MySQL AND MariaDB.
FAILED_SQL="$ROOT/fixture-failing-sql.wpress"
php "$GITHUB_WORKSPACE/tests/inject-sql-failure.php" "$ARCHIVE" "$FAILED_SQL"
cp "$FAILED_SQL" "$ROOT/target/wp-content/trasloco-backups/"
if FAILURE_LOG=$($WP trasloco restore "$(basename "$FAILED_SQL")" --path="$ROOT/target" 2>&1); then
  echo "ERROR: a real invalid SQL statement did not fail the import" >&2
  exit 1
fi
printf '%s\n' "$FAILURE_LOG" | grep -F 'trasloco_ci_forced_sql_failure_2026'
# A real partial restore happened: database.sql was executed before its final
# invalid statement. Confirm the preexisting destination user was replaced.
$WP user get oldadmin --path="$ROOT/target" --field=user_login | grep -Fx oldadmin
# A failed restore may replace active_plugins before the final "Done" stage.
# The operator must still be able to re-enable the existing plugin and recover.
$WP plugin activate trasloco --path="$ROOT/target"
FAILED_RECOVERY=$(find "$ROOT/target/wp-content/trasloco-backups" -maxdepth 1 \
  -type f -name 'recovery-*.wpress' -print -quit)
test -s "$FAILED_RECOVERY"
test -s "$FAILED_RECOVERY.manifest.json"
PENDING_RECEIPT=$(find "$ROOT/target/wp-content/trasloco-backups" -maxdepth 1 \
  -type f -name '.trasloco-recovery-*.json' -print -quit)
test -s "$PENDING_RECEIPT"
php -r '
  $receipt = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($receipt) || $receipt["status"] !== "pending" ||
      $receipt["recovery_archive"] !== basename($argv[2]) ||
      $receipt["source_archive"] !== basename($argv[3])) {
      fwrite(STDERR, "Failed SQL import lost its recovery receipt\n"); exit(1);
  }
' "$PENDING_RECEIPT" "$FAILED_RECOVERY" "$FAILED_SQL"
# Offline discovery works even if the imported WordPress database will not boot.
OFFLINE_RECEIPTS=$(php "$GITHUB_WORKSPACE/tools/recovery-status.php"   "$ROOT/target/wp-content/trasloco-backups" --json)
printf '%s' "$OFFLINE_RECEIPTS" | php -r '
  $records = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($records) || count($records) < 1 ||
      $records[0]["status"] !== "pending" ||
      $records[0]["recovery_archive"] !== basename($argv[1])) {
      fwrite(STDERR, "Offline rescue receipt discovery failed\n"); exit(1);
  }
' "$(basename "$FAILED_RECOVERY")"
# Recovery receipts are visible only to administrators; API includes no paths.
$WP eval '
wp_set_current_user( 0 );
$denied = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/recovery-points" ) );
if ( $denied->get_status() < 400 ) { throw new Exception( "Anonymous recovery journal disclosed" ); }
$admin = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admin[0]->ID );
$found = rest_do_request( new WP_REST_Request( "GET", "/trasloco/v1/recovery-points" ) );
$data = $found->get_data();
if ( $found->get_status() !== 200 || empty( $data["pending"] ) ||
     $data["pending"][0]["status"] !== "pending" ) {
    throw new Exception( "Pending recovery not visible to administrator" );
}
if ( strpos( wp_json_encode( $data ), ABSPATH ) !== false ) {
    throw new Exception( "Recovery API disclosed filesystem paths" );
}
' --path="$ROOT/target"
$WP trasloco verify "$(basename "$FAILED_RECOVERY")" --path="$ROOT/target"
# A recovery point must expose changed and missing files independently
# of the potentially damaged WordPress/MySQL installation.
# A partially failed import may leave a missing target file. Inject that
# specific filesystem damage here to exercise the offline comparison while
# the destination database has already suffered the real post-DDL SQL error.
rm -f "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
RECOVERY_MISSING=$(php "$GITHUB_WORKSPACE/tools/recovery-diff.php" "$FAILED_RECOVERY" "$ROOT/target/wp-content" --json)
# The archive includes the original destination bytes; missing is detected.

printf '%s' "$RECOVERY_MISSING" | php -r '
  $data = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($data) || $data["missing"] < 1) {
      fwrite(STDERR, "Offline comparison missed a deleted target file\n"); exit(1);
  }
  $found = false;
  foreach ($data["sample"] as $entry) {
      if ($entry["relative_path"] === "uploads/target-before-import/safety-marker.txt" &&
          $entry["state"] === "missing") { $found = true; }
  }
  if (!$found) { fwrite(STDERR, "Missing destination media not reported\n"); exit(1); }
'
# Simulate a same-path, different-content survivor of a partial import.
mkdir -p "$ROOT/target/wp-content/uploads/target-before-import"
printf 'CHANGED BY FAILED IMPORT\n' > "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
RECOVERY_DIFF=$(php "$GITHUB_WORKSPACE/tools/recovery-diff.php" "$FAILED_RECOVERY" "$ROOT/target/wp-content" --json)
printf '%s' "$RECOVERY_DIFF" | php -r '
  $data = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($data) || $data["modified"] < 1 || $data["integrity"] !== "sha256_verified") {
      fwrite(STDERR, "Offline recovery diff failed to detect altered media\n"); exit(1);
  }
  $found = false;
  foreach ($data["sample"] as $entry) {
      if ($entry["relative_path"] === "uploads/target-before-import/safety-marker.txt" &&
          $entry["state"] === "modified") { $found = true; }
  }
  if (!$found) { fwrite(STDERR, "Expected recovery media was not reported\n"); exit(1); }
'

# An interrupted restore can also leave files ABSENT FROM the original
# snapshot. They may be imported files or legitimate later writes: report,
# never delete them automatically.
printf 'LATE FILE AFTER FAILED IMPORT\n' > "$ROOT/target/wp-content/uploads/target-before-import/untracked-after-failure.txt"
UNTRACKED_REPORT=$(php "$GITHUB_WORKSPACE/tools/recovery-diff.php" "$FAILED_RECOVERY" "$ROOT/target/wp-content" --scan-untracked --json)
printf '%s' "$UNTRACKED_REPORT" | php -r '
  $data = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($data) || $data["untracked_scan"] !== "completed" ||
      $data["not_in_snapshot"] < 1 || $data["destination_entries_scanned"] < 1) {
      fwrite(STDERR, "Untracked recovery inventory failed\n"); exit(1);
  }
  $found = false;
  foreach ($data["untracked_sample"] as $entry) {
      if ($entry["relative_path"] === "uploads/target-before-import/untracked-after-failure.txt" &&
          $entry["state"] === "not_in_snapshot") { $found = true; }
  }
  if (!$found) { fwrite(STDERR, "Post-failure untracked file not reported\n"); exit(1); }
'
test -f "$ROOT/target/wp-content/uploads/target-before-import/untracked-after-failure.txt"

# Two-phase, independent filesystem rescue requires no WordPress bootstrap.
# The operator explicitly confirms that the target site is offline. A prior
# copy of each replaced file remains in the private workspace for reversal.
# A restrictive existing file must stay private after replacing its bytes.
printf 'modified private content\n' > "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
chmod 600 "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
RESCUE_OVERLAY="$ROOT/file-overlay-recovery"
php "$GITHUB_WORKSPACE/tools/recovery-files.php" prepare \
  "$FAILED_RECOVERY" "$ROOT/target/wp-content" "$RESCUE_OVERLAY"
test -f "$RESCUE_OVERLAY/plan.json"
test -f "$RESCUE_OVERLAY/originals/uploads/target-before-import/safety-marker.txt" || \
  test ! -f "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
php "$GITHUB_WORKSPACE/tools/recovery-files.php" apply \
  "$RESCUE_OVERLAY" "$ROOT/target/wp-content" --site-offline-confirmed
cmp -s "$ROOT/target-safety-marker.expected" \
  "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
test -f "$ROOT/target/wp-content/uploads/target-before-import/untracked-after-failure.txt"
php -r 'clearstatcache(); if ((fileperms($argv[1]) & 0777) !== 0600) { fwrite(STDERR, "Recovery widened private file permissions\n"); exit(1); }' \
  "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
php "$GITHUB_WORKSPACE/tools/recovery-files.php" apply \
  "$RESCUE_OVERLAY" "$ROOT/target/wp-content" --site-offline-confirmed | grep -F '"already_completed": true'

# Operator-driven rollback: do NOT silently restore in the failed import
# process, whose database and active plugin set may already be inconsistent.
$WP trasloco restore "$(basename "$FAILED_RECOVERY")" --path="$ROOT/target"
# The importer restores archived files and SQL, but it leaves extra files
# behind. Prepare and inspect a separate quarantine only after SQL recovery so
# a changed candidate cannot be silently discarded by the standard import.
FULL_SITE_FILES="$ROOT/full-site-file-recovery"
php "$GITHUB_WORKSPACE/tools/recovery-files.php" prepare \
  "$FAILED_RECOVERY" "$ROOT/target/wp-content" "$FULL_SITE_FILES" --include-untracked > "$ROOT/full-site-prepare.json"
php "$GITHUB_WORKSPACE/tools/recovery-files.php" review "$FULL_SITE_FILES" > "$ROOT/full-site-review.json"
FULL_SITE_APPROVAL=$(php -r '
  $prepared = json_decode(file_get_contents($argv[1]), true);
  $review = json_decode(file_get_contents($argv[2]), true);
  $expected = "uploads/target-before-import/untracked-after-failure.txt";
  $found = false;
  $files = is_array($review["quarantine_files"] ?? null) ? $review["quarantine_files"] : array();
  foreach ($files as $entry) {
      if (($entry["relative_path"] ?? null) === $expected) { $found = true; }
  }
  if (!is_array($prepared) || !is_array($review) ||
      !isset($prepared["quarantine_candidates"], $prepared["approval_sha256"]) ||
      !isset($review["approval_sha256"]) ||
      $prepared["quarantine_candidates"] < 1 ||
      $prepared["quarantine_candidates"] !== count($files) ||
      !hash_equals($prepared["approval_sha256"], $review["approval_sha256"]) ||
      !$found) {
      fwrite(STDERR, "Full-site rollback did not produce the expected reviewed file set: " .
          json_encode(array("prepared" => $prepared, "review" => $review), JSON_UNESCAPED_SLASHES) . "\n"); exit(1);
  }
  echo $review["approval_sha256"];
' "$ROOT/full-site-prepare.json" "$ROOT/full-site-review.json")
php "$GITHUB_WORKSPACE/tools/recovery-files.php" apply \
  "$FULL_SITE_FILES" "$ROOT/target/wp-content" --site-offline-confirmed \
  --approve-untracked="$FULL_SITE_APPROVAL"
test ! -e "$ROOT/target/wp-content/uploads/target-before-import/untracked-after-failure.txt"
test -f "$FULL_SITE_FILES/quarantine/uploads/target-before-import/untracked-after-failure.txt"
grep -Fx 'LATE FILE AFTER FAILED IMPORT' \
  "$FULL_SITE_FILES/quarantine/uploads/target-before-import/untracked-after-failure.txt"
# SQL recovery can finish while the plugin leaves files deleted by the
# interrupted import. The reviewed file overlay must restore the original.
if ! cmp -s "$ROOT/target-safety-marker.expected" "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"; then
  echo "KNOWN P0: database rollback completed but original destination media still needs operator-driven file recovery."
  # Independently extract into a NEW private directory: do not overwrite live
  # content. Prove recovery bytes are actually available to the operator.
  STAGED_FILE_RESCUE="$ROOT/failed-sql-file-rescue"
  php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$FAILED_RECOVERY" --content --output="$STAGED_FILE_RESCUE"
  cmp -s "$ROOT/target-safety-marker.expected" "$STAGED_FILE_RESCUE/content/uploads/target-before-import/safety-marker.txt"
  POST_FAILURE_DIFF=$(php "$GITHUB_WORKSPACE/tools/recovery-diff.php" "$FAILED_RECOVERY" "$ROOT/target/wp-content" --json)
  printf '%s' "$POST_FAILURE_DIFF" | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data) || $data["missing"] + $data["modified"] < 1) {
        fwrite(STDERR, "Recovery marked as complete despite missing live media\n"); exit(1);
    }
  '
fi
# This file-level recovery check does not assert that imported-only files were
# removed. The diff deliberately never deletes or overwrites site contents.
$WP user get newadmin --path="$ROOT/target" --field=user_login | grep -Fx newadmin
if $WP user get oldadmin --path="$ROOT/target" --field=user_login >/dev/null 2>&1; then
  echo "ERROR: restored destination still has the migrated user" >&2
  exit 1
fi
test "$($WP option get home --path="$ROOT/target")" = "$EXPECTED_HOME"
php -r '
  $receipt = json_decode(file_get_contents($argv[1]), true);
  if ($receipt["status"] !== "pending") {
      fwrite(STDERR, "Failed operation was incorrectly marked completed\n"); exit(1);
  }
' "$PENDING_RECEIPT"
rm "$ROOT/target/wp-content/trasloco-backups/$(basename "$FAILED_SQL")"
echo "PASS: real SQL fault preserved receipt and verified recovery restored destination database."

# Recover an actual WordPress backup using only system PHP (no WP bootstrap).
# The rescue command supports a deterministic private directory so operators can
# plan storage and rehearse recovery instead of relying on transient /tmp paths.
RESCUE_DIR="$ROOT/offline-rehearsal"
RESCUE_REPORT=$(php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$ARCHIVE" --content --output="$RESCUE_DIR")
RESCUE_CANONICAL_DIR=$(cd "$RESCUE_DIR" && pwd -P)
printf '%s\n' "$RESCUE_REPORT" | grep -F "Recovery metadata saved in: $RESCUE_CANONICAL_DIR"
printf '%s\n' "$RESCUE_REPORT" | grep -F 'Archive integrity: sha256_verified'
test -s "$RESCUE_DIR/database.sql"
test -s "$RESCUE_DIR/package.json"
test -f "$RESCUE_DIR/content/uploads/test-fixtures/fixture.txt"
cmp -s "$ROOT/source/wp-content/uploads/test-fixtures/fixture.txt" "$RESCUE_DIR/content/uploads/test-fixtures/fixture.txt"
# Safe recovery never overwrites a previous rehearsal and never creates a
# relative destination that could accidentally point into a live site.
if php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$ARCHIVE" --output="$RESCUE_DIR" >/dev/null 2>&1; then
  echo "ERROR: rescue overwrote an existing directory" >&2
  exit 1
fi
if php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$ARCHIVE" --output=relative-recovery >/dev/null 2>&1; then
  echo "ERROR: rescue accepted a relative output directory" >&2
  exit 1
fi
test -s "$RESCUE_DIR/database.sql"
test -f "$RESCUE_DIR/content/uploads/test-fixtures/fixture.txt"
# Corrupted source+unmodified sidecar must abort before any recovery folder
# or partial plaintext is created. Never give tampered bytes a green result.
cp "$ARCHIVE" "$ROOT/corrupt-rescue.wpress"
cp "$ARCHIVE.manifest.json" "$ROOT/corrupt-rescue.wpress.manifest.json"
printf 'X' | dd of="$ROOT/corrupt-rescue.wpress" bs=1 seek=100 count=1 conv=notrunc status=none
if php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$ROOT/corrupt-rescue.wpress" --content --output="$ROOT/corrupt-rescue-out" >/dev/null 2>&1; then
  echo "ERROR: offline rescue accepted corrupted archive" >&2
  exit 1
fi
test ! -e "$ROOT/corrupt-rescue-out"
rm "$ROOT/corrupt-rescue.wpress" "$ROOT/corrupt-rescue.wpress.manifest.json"
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
  # A broken WordPress must not prevent decrypting its authenticated backup.
  INDEPENDENT="$ROOT/offline-decrypted.wpress"
  TRASLOCO_ARCHIVE_PASSPHRASE='integration-crypt-password-2026' php "$GITHUB_WORKSPACE/tools/rescue-decrypt.php" "$ARCHIVE.enc" "$INDEPENDENT"
  cmp -s "$ARCHIVE" "$INDEPENDENT"
  # Refuse to overwrite an existing recovery artifact.
  if TRASLOCO_ARCHIVE_PASSPHRASE='integration-crypt-password-2026' php "$GITHUB_WORKSPACE/tools/rescue-decrypt.php" "$ARCHIVE.enc" "$INDEPENDENT"; then
    echo "ERROR: independent decrypt overwrote an existing archive" >&2
    exit 1
  fi
  # Wrong passphrase must not leave behind a partial plaintext archive.
  if TRASLOCO_ARCHIVE_PASSPHRASE='wrong-passphrase-for-test' php "$GITHUB_WORKSPACE/tools/rescue-decrypt.php" "$ARCHIVE.enc" "$ROOT/incorrect.wpress"; then
    echo "ERROR: independent decrypt accepted the wrong password" >&2
    exit 1
  fi
  test ! -e "$ROOT/incorrect.wpress"
  rm "$INDEPENDENT"
fi
test -s "$ARCHIVE.manifest.json"
mkdir -p "$ROOT/target/wp-content/trasloco-backups"
# Distinct target-only media should be present in its pre-import recovery
# snapshot, even if the live import subsequently replaces that file tree.
mkdir -p "$ROOT/target/wp-content/uploads/target-before-import"
printf 'target media survived independent rescue\n' > "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt"
cp "$ROOT/target/wp-content/uploads/target-before-import/safety-marker.txt" "$ROOT/target-safety-marker.expected"
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
# Multiple legitimate restore attempts now create multiple recovery files.
# Select the receipt belonging to THIS successful restore, not the first
# filesystem entry (directory iteration order is not stable).
RECOVERY_NAME=$(php "$GITHUB_WORKSPACE/tools/recovery-status.php" \
  "$ROOT/target/wp-content/trasloco-backups" --json | php -r '
  $rows = json_decode(stream_get_contents(STDIN), true);
  foreach ((array) $rows as $entry) {
    if ($entry["status"] === "completed" &&
        $entry["source_archive"] === $argv[1]) {
        echo $entry["recovery_archive"]; exit(0);
    }
  }
  fwrite(STDERR, "No completed recovery receipt for the selected import\n"); exit(1);
' "$(basename "$ARCHIVE")")
RECOVERY="$ROOT/target/wp-content/trasloco-backups/$RECOVERY_NAME"
test -n "$RECOVERY"
test -s "$RECOVERY.manifest.json"
[[ "$(basename "$RECOVERY")" == recovery-*.wpress ]]
$WP trasloco verify "$(basename "$RECOVERY")" --path="$ROOT/target"
# The independently bootable PHP rescue must read the recovery point created
# *before* import, preserving original target-only files and original SQL.
RECOVERY_STAGE="$ROOT/offline-recovery-point"
php "$GITHUB_WORKSPACE/tools/rescue-extract.php" "$RECOVERY" --content --output="$RECOVERY_STAGE" >/dev/null
test -s "$RECOVERY_STAGE/database.sql"
test -s "$RECOVERY_STAGE/package.json"
cmp -s "$ROOT/target-safety-marker.expected" "$RECOVERY_STAGE/content/uploads/target-before-import/safety-marker.txt"

# Kill an actual WP-CLI import while MySQL is executing a deliberately slow
# statement after the archive's DDL/DML, then replay the same operation. This
# verifies process-crash receipt durability and restart behavior, not atomic
# rollback of changes already committed by MySQL DDL.
CRASH_ARCHIVE="$ROOT/target/wp-content/trasloco-backups/crash-replay.wpress"
php "$GITHUB_WORKSPACE/tests/inject-sql-failure.php" "$ARCHIVE" "$CRASH_ARCHIVE" sleep
$WP plugin activate trasloco --path="$ROOT/target"
$WP trasloco restore "$(basename "$CRASH_ARCHIVE")" --path="$ROOT/target" > "$ROOT/crash-restore.log" 2>&1 &
CRASH_PID=$!
SLEEP_QUERY_SEEN=0
# HPOS introduces more tables, so allow up to three minutes for the importer
# to reach the deliberately slow statement on slower local/CI database hosts.
for attempt in $(seq 1 1800); do
  if "${MYSQL[@]}" -N -e 'SHOW FULL PROCESSLIST' 2>/dev/null | grep -Fq 'SELECT SLEEP(30)'; then
    SLEEP_QUERY_SEEN=1
    break
  fi
  if ! kill -0 "$CRASH_PID" 2>/dev/null; then
    break
  fi
  sleep 0.1
done
if [ "$SLEEP_QUERY_SEEN" != 1 ]; then
  kill -KILL "$CRASH_PID" 2>/dev/null || true
  wait "$CRASH_PID" 2>/dev/null || true
  /usr/bin/sed -n '1,120p' "$ROOT/crash-restore.log"
  echo "ERROR: importer never reached the post-DDL crash point" >&2
  exit 1
fi
kill -KILL "$CRASH_PID"
wait "$CRASH_PID" 2>/dev/null || true
CRASH_RECEIPT=$(find "$ROOT/target/wp-content/trasloco-backups" -maxdepth 1 \
  -type f -name '.trasloco-recovery-*.json' -exec grep -q 'crash-replay.wpress' {} \; -print -quit)
test -s "$CRASH_RECEIPT"
php -r '
  $receipt = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($receipt) || $receipt["status"] !== "pending" ||
      $receipt["source_archive"] !== "crash-replay.wpress") {
      fwrite(STDERR, "SIGKILL during SQL import did not preserve a pending receipt\n"); exit(1);
  }
' "$CRASH_RECEIPT"
# Replace only the test archive's trailing pause with the original fixture
# bytes, retaining the archive name for the successful replay attempt.
cp "$ARCHIVE" "$CRASH_ARCHIVE"
$WP plugin activate trasloco --path="$ROOT/target"
$WP trasloco restore "$(basename "$CRASH_ARCHIVE")" --path="$ROOT/target"
php -r '
  require $argv[2];
  $crashed = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($crashed) || !isset($crashed["id"]) ||
      $crashed["status"] !== "pending" || $crashed["source_archive"] !== "crash-replay.wpress") {
    fwrite(STDERR, "Original SIGKILL receipt was not preserved as pending\n"); exit(1);
  }
  $completed = false;
  foreach (glob(dirname($argv[1]) . "/.trasloco-recovery-*.json") as $path) {
    $entry = json_decode(file_get_contents($path), true);
    if (is_array($entry) && $entry["source_archive"] === "crash-replay.wpress" &&
        $entry["status"] === "completed" && $entry["id"] !== $crashed["id"]) {
      $completed = true;
    }
  }
  if (!$completed) {
      fwrite(STDERR, "Restarted import did not complete while preserving the crash receipt\n"); exit(1);
  }
' "$CRASH_RECEIPT" "$GITHUB_WORKSPACE/lib/model/import/class-trasloco-recovery-journal.php"
$WP user get oldadmin --path="$ROOT/target" --field=user_login | grep -Fx oldadmin
echo "PASS: SIGKILL after post-DDL SQL preserved its pending receipt; a successful replay completed with a separate receipt."
if [ -n "$WC_ORDER_ID" ]; then
  $WP eval-file "$GITHUB_WORKSPACE/tests/woocommerce-fixture.php" verify \
    "$WC_ORDER_ID" "$WC_REFUND_ID" "$WC_PRODUCT_ID" "$WC_ORDER_STORE" --path="$ROOT/target"
  echo "PASS: WooCommerce $WC_ORDER_STORE order, refund, product line, metadata and note survived backup and restore."
fi

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

# Safety regression: an expired backup lease must NEVER be reclaimed.
# A previous process could still be writing its archive when the scheduler
# runs, so a second worker would be able to destroy or duplicate work.
$WP eval '
global $wpdb;
$backup = Trasloco_Job_Store::create(
    "backup", array( "retention" => 2 ), "ci-expired-backup-lease-0001"
);
$verify = Trasloco_Job_Store::create(
    "verify", array( "archive" => "missing.wpress" ), "ci-expired-verify-lease-0001"
);
if ( is_wp_error( $backup ) || is_wp_error( $verify ) ) {
    throw new Exception( "Unable to create stale lease fixtures" );
}
$table = Trasloco_Job_Store::table();
foreach ( array( $backup, $verify ) as $id ) {
    $wpdb->update( $table, array(
        "state" => "running", "lease_until" => time() - 120,
        "lease_owner" => "former-worker-12345", "attempts" => 1,
    ), array( "id" => $id ) );
}
if ( Trasloco_Job_Store::claim( $backup, "new-worker-1234567" ) ) {
    throw new Exception( "An expired backup was dangerously restarted" );
}
if ( ! Trasloco_Job_Store::claim( $verify, "new-worker-1234567" ) ) {
    throw new Exception( "Read-only verification could not recover an expired lease" );
}
if ( Trasloco_Job_Store::get( $verify )["attempts"] !== 2 ||
     ! Trasloco_Job_Store::finish( $verify, "new-worker-1234567" ) ) {
    throw new Exception( "Verification lease was not transferred correctly" );
}
if ( Trasloco_Job_Store::fail_abandoned_backups() < 1 ||
     Trasloco_Job_Store::get( $backup )["state"] !== "failed" ||
     Trasloco_Job_Store::claim( $backup, "new-worker-1234567" ) ) {
    throw new Exception( "Abandoned backup was not left in a terminal state" );
}
' --path="$ROOT/source"

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
// Collision must never overwrite an existing recoverable archive.
$collision = fopen( "php://temp", "w+b" );
fwrite( $collision, "replacement bytes" );
rewind( $collision );
try {
    $store->put_stream( "fixture.wpress", $collision );
    throw new Exception( "Storage silently overwrote an existing backup" );
} catch ( RuntimeException $expected ) {}
fclose( $collision );
$proof = $store->open_read( "fixture.wpress" );
if ( stream_get_contents( $proof ) !== "binary payload" ) {
    throw new Exception( "Collision corrupted original backup" );
}
fclose( $proof );
foreach ( scandir( $root ) as $filename ) {
    if ( strpos( $filename, ".tr-writing-" ) === 0 ) {
        throw new Exception( "Temporary backup bytes remained after failed publication" );
    }
}
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

# REST inventory supports bounded, backwards-compatible pagination.
$WP eval '
$admins = get_users( array( "role" => "administrator", "number" => 1 ) );
wp_set_current_user( $admins[0]->ID );
$request = new WP_REST_Request( "GET", "/trasloco/v1/backups" );
$request->set_param( "page", 1 );
$request->set_param( "per_page", 1 );
$result = rest_do_request( $request );
$data = $result->get_data();
if ( $result->get_status() !== 200 || count( $data["backups"] ) !== 1 ||
    $data["page"] !== 1 || $data["per_page"] !== 1 ||
    (int) $result->get_headers()["X-WP-Total"] < 1 ) {
    throw new Exception( "Paged backup listing failed" );
}
$invalid = new WP_REST_Request( "GET", "/trasloco/v1/backups" );
$invalid->set_param( "per_page", 0 );
if ( rest_do_request( $invalid )->get_status() !== 400 ) {
    throw new Exception( "Invalid page size accepted" );
}
$invalid->set_param( "per_page", 101 );
if ( rest_do_request( $invalid )->get_status() !== 400 ) {
    throw new Exception( "Unbounded page size accepted" );
}
$invalid->set_param( "per_page", 1 );
$invalid->set_param( "page", -5 );
if ( rest_do_request( $invalid )->get_status() !== 400 ) {
    throw new Exception( "Negative page accepted" );
}
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
