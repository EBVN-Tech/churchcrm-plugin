#!/usr/bin/env bash
# Builds a plugin's zip: scripts/build.sh <id>
# Produces dist/<id>-<version>.zip (a single <id> root folder, no hidden files,
# README, CHANGELOG, version.txt or docs) and prints its SHA-256.
set -euo pipefail

cd "$(dirname "$0")/.."

id="${1:?usage: scripts/build.sh <id>}"
src="plugins/$id"
[ -f "$src/plugin.json" ] || { echo "plugin not found: $src/plugin.json" >&2; exit 1; }

json_value() {
    sed -n "s/^ *\"$1\": *\"\([^\"]*\)\".*/\1/p" "$src/plugin.json" | head -n 1
}

manifest_id="$(json_value id)"
version="$(json_value version)"
[ "$manifest_id" = "$id" ] || { echo "plugin.json id ($manifest_id) differs from the folder ($id)" >&2; exit 1; }
[ -n "$version" ] || { echo "version missing from $src/plugin.json" >&2; exit 1; }

zip="dist/$id-$version.zip"
rm -rf "dist/$id" "$zip"
mkdir -p "dist/$id"
rsync -a --exclude '.*' --exclude 'README.md' --exclude 'CHANGELOG.md' --exclude 'version.txt' --exclude 'docs/' "$src/" "dist/$id/"
(cd dist && zip -X -r -q "$id-$version.zip" "$id")
rm -rf "dist/$id"

if command -v sha256sum >/dev/null; then sha256sum "$zip"; else shasum -a 256 "$zip"; fi
