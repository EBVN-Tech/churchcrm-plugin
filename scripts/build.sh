#!/usr/bin/env bash
# Construit le zip d'un plugin : scripts/build.sh <id>
# Produit dist/<id>-<version>.zip (un seul dossier racine <id>, aucun fichier caché,
# ni README ni docs) et affiche son SHA-256.
set -euo pipefail

cd "$(dirname "$0")/.."

id="${1:?usage : scripts/build.sh <id>}"
src="plugins/$id"
[ -f "$src/plugin.json" ] || { echo "plugin introuvable : $src/plugin.json" >&2; exit 1; }

json_value() {
    sed -n "s/^ *\"$1\": *\"\([^\"]*\)\".*/\1/p" "$src/plugin.json" | head -n 1
}

manifest_id="$(json_value id)"
version="$(json_value version)"
[ "$manifest_id" = "$id" ] || { echo "l'id de plugin.json ($manifest_id) diffère du dossier ($id)" >&2; exit 1; }
[ -n "$version" ] || { echo "version absente de $src/plugin.json" >&2; exit 1; }

zip="dist/$id-$version.zip"
rm -rf "dist/$id" "$zip"
mkdir -p "dist/$id"
rsync -a --exclude '.*' --exclude 'README.md' --exclude 'docs/' "$src/" "dist/$id/"
(cd dist && zip -X -r -q "$id-$version.zip" "$id")
rm -rf "dist/$id"

if command -v sha256sum >/dev/null; then sha256sum "$zip"; else shasum -a 256 "$zip"; fi
