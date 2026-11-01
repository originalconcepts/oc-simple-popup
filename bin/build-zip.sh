#!/usr/bin/env bash
# Build the release zip the OC Pluginz hub serves as the update package.
#
# The hub reads the version from simple-popup/simple-popup.php inside the zip,
# and WordPress replaces the installed folder with the zip's top folder — so
# the top folder MUST be simple-popup/ (the installed plugin folder), whatever
# the repository is called.
#
# Usage: bash bin/build-zip.sh            → dist/simple-popup.zip
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="simple-popup"
out="$root/dist/$slug.zip"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/$slug" "$root/dist"

# Everything the plugin needs at runtime; nothing that only the repository needs.
rsync -a "$root/" "$stage/$slug/" \
  --exclude '.git' --exclude '.github' --exclude 'bin' --exclude 'dist' \
  --exclude '.gitignore' --exclude '*.zip' --exclude '*.bak' \
  --exclude '.DS_Store' --exclude 'Thumbs.db'

rm -f "$out"
( cd "$stage" && zip -qr "$out" "$slug" -x '*/.*' )

version="$(grep -m1 -E '^\s*\*\s*Version:' "$root/$slug.php" | sed -E 's/.*Version:\s*//' | tr -d '\r')"
echo "built $out (version $version)"
unzip -l "$out"
