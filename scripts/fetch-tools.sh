#!/usr/bin/env bash
# Downloads the review tools into a folder: scripts/fetch-tools.sh <dir>
# <dir>/churchcrm (ChurchCRM release, for PHPStan), <dir>/phpstan.phar and
# <dir>/plugin-scan.php (ChurchCRM's community plugin scan), each pinned by SHA-256.
set -euo pipefail

CHURCHCRM_VERSION=7.8.0
CHURCHCRM_SHA256=0a2efd069bdeaa9535f76ca3e347f2e729890a8ad7951d7692fec0d060296fb1
PHPSTAN_VERSION=2.3.3
PHPSTAN_SHA256=aa5cc7543f0de0ae7997deb24f654a3b2454b6ba4ba2c4552957a175886b60b1
PLUGIN_SCAN_COMMIT=de417ffa845a8f6c68905740b34478537543bc05
PLUGIN_SCAN_SHA256=5331f82f34a4a0f5904544fb8a999e23ea967b79dda0dd576f6816d3a69ecbf4

dir="${1:?usage: scripts/fetch-tools.sh <dir>}"
mkdir -p "$dir"
cd "$dir"

curl -fsSL -o churchcrm.zip "https://github.com/ChurchCRM/CRM/releases/download/$CHURCHCRM_VERSION/ChurchCRM-$CHURCHCRM_VERSION.zip"
curl -fsSL -o phpstan.phar "https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_VERSION/phpstan.phar"
curl -fsSL -o plugin-scan.php "https://raw.githubusercontent.com/ChurchCRM/CRM/$PLUGIN_SCAN_COMMIT/scripts/plugin-scan.php"

sha256sum -c - <<EOF
$CHURCHCRM_SHA256  churchcrm.zip
$PHPSTAN_SHA256  phpstan.phar
$PLUGIN_SCAN_SHA256  plugin-scan.php
EOF

rm -rf churchcrm
unzip -q churchcrm.zip
