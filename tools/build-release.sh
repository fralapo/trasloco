#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WITH_S3=0
for ARG in "$@"; do
  case "$ARG" in
    --with-s3) WITH_S3=1 ;;
    *) printf 'Usage: %s [--with-s3]\n' "$0" >&2; exit 2 ;;
  esac
done

if ! git -C "$ROOT" diff --quiet || ! git -C "$ROOT" diff --cached --quiet; then
  printf 'Refusing to package uncommitted source changes. Commit or stash them first.\n' >&2
  exit 1
fi

VERSION="$(sed -n 's/^ \* Version: //p' "$ROOT/trasloco.php" | head -n 1)"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  printf 'Could not read a stable plugin version from trasloco.php.\n' >&2
  exit 1
fi

OUTPUT_DIR="$ROOT/dist"
PACKAGE_NAME="trasloco-${VERSION}"
if [[ "$WITH_S3" == 1 ]]; then
  PACKAGE_NAME+="-s3"
fi
OUTPUT="$OUTPUT_DIR/${PACKAGE_NAME}.zip"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/trasloco-release.XXXXXX")"
cleanup() {
  rm -rf "$STAGING"
}
trap cleanup EXIT

mkdir -p "$OUTPUT_DIR" "$STAGING/trasloco"
git -C "$ROOT" archive --format=tar HEAD | tar -xf - -C "$STAGING/trasloco"

if [[ "$WITH_S3" == 1 ]]; then
  if ! command -v composer >/dev/null 2>&1; then
    printf 'Composer is required to build the S3-enabled plugin ZIP.\n' >&2
    exit 1
  fi
  (
    cd "$STAGING/trasloco"
    COMPOSER=composer.s3.json composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
  )
  if [[ ! -r "$STAGING/trasloco/vendor-s3/autoload.php" ]]; then
    printf 'S3 SDK autoloader is missing from the release staging directory.\n' >&2
    exit 1
  fi
fi

rm -f "$OUTPUT"
( cd "$STAGING" && zip -qr "$OUTPUT" trasloco )
if ! unzip -tq "$OUTPUT" >/dev/null; then
  rm -f "$OUTPUT"
  printf 'The generated plugin ZIP failed its archive integrity check.\n' >&2
  exit 1
fi
if [[ "$WITH_S3" == 1 ]]; then
  if ! unzip -Z1 "$OUTPUT" | grep -x 'trasloco/vendor-s3/autoload.php' >/dev/null; then
    rm -f "$OUTPUT"
    printf 'The S3 release ZIP does not contain its runtime autoloader.\n' >&2
    exit 1
  fi
fi
printf 'Created %s\n' "$OUTPUT"
