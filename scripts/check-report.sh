#!/usr/bin/env bash
# Runs the checks and prints a Markdown report: scripts/check-report.sh <tools-dir>
# <tools-dir> comes from scripts/fetch-tools.sh. Posted on release PRs, so the
# reviewer sees what was checked on the exact content about to be released.
set -uo pipefail

cd "$(dirname "$0")/.."
tools="${1:?usage: scripts/check-report.sh <tools-dir>}"
export CHURCHCRM_DIR="$tools/churchcrm"

status() { [ "$1" -eq 0 ] && echo "✅ pass" || echo "❌ fail"; }

echo "## Checks on this release"
echo
echo "Commit: \`$(git rev-parse --short HEAD)\`, PHP $(php -r 'echo PHP_VERSION;')."
echo
echo "| Check | Result |"
echo "|---|---|"

find plugins -name '*.php' -print0 | xargs -0 -n 1 php -l >/dev/null 2>&1
echo "| PHP syntax (\`php -l\`) | $(status $?) |"

php scripts/check-plugins.php >/dev/null 2>&1
echo "| \`plugin.json\` against ChurchCRM's rules | $(status $?) |"

phpstan_out="$(php -d memory_limit=1G "$tools/phpstan.phar" analyse --no-progress --error-format=raw 2>/dev/null)"
phpstan_rc=$?
echo "| PHPStan level 6, against ChurchCRM 7.8.0 | $(status $phpstan_rc) |"

for dir in plugins/*/; do
    id="$(basename "$dir")"
    scan="$(php "$tools/plugin-scan.php" "$dir" 2>&1)"
    scan_rc=$?
    counts="$(echo "$scan" | sed -n 's/^\(errors\|warnings\): *\([0-9]*\)$/\1 \2/p' | paste -sd ',' - | sed 's/,/, /')"
    echo "| ChurchCRM \`plugin-scan.php\`: \`$id\` | $(status $scan_rc) ($counts) |"

    scripts/build.sh "$id" >/dev/null 2>&1
    echo "| Zip build: \`$id\` | $(status $?) |"
done

if [ "$phpstan_rc" -ne 0 ]; then
    echo
    echo "<details><summary>PHPStan errors</summary>"
    echo
    echo '```'
    echo "$phpstan_out" | grep -v '^$' | head -n 50
    echo '```'
    echo "</details>"
fi
