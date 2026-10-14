#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
umask 077
if [[ ! -r "$ROOT/vendor-s3/autoload.php" ]]; then
  printf 'Install the optional SDK first: COMPOSER=composer.s3.json composer install\n' >&2
  exit 1
fi

PORT="$(php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); if (!$server) { exit(1); } $name = stream_socket_get_name($server, false); echo substr(strrchr($name, ":"), 1); fclose($server);')"
STATE="${TMPDIR:-/tmp}/trasloco-s3-wire-${PORT}.json"
: > "$STATE"
export TRASLOCO_S3_WIRE_STATE="$STATE"
php -S "127.0.0.1:$PORT" "$ROOT/tests/s3-wire-fixture.php" >/dev/null 2>&1 &
SERVER_PID=$!
cleanup() {
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
  php -r 'if (is_file($argv[1])) { unlink($argv[1]); }' "$STATE"
}
trap cleanup EXIT

READY=0
for ATTEMPT in {1..50}; do
  if php -r '$connection = @fsockopen("127.0.0.1", (int) $argv[1], $errno, $error, 0.2); if (!$connection) { exit(1); } fclose($connection);' "$PORT"; then
    READY=1
    break
  fi
  sleep 0.1
done
if [[ "$READY" != 1 ]]; then
  printf 'Local S3 fixture server did not start.\n' >&2
  exit 1
fi
php "$ROOT/tests/s3-wire-smoke.php" "$PORT"
