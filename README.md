<div align="center">

# 🧩 ChurchCRM Plugins

**ChurchCRM plugins, one folder per plugin.**

![ChurchCRM](https://img.shields.io/badge/ChurchCRM-%E2%89%A5%207.8.0-2a6db0)
![PHP](https://img.shields.io/badge/PHP-8.4-777bb4)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Buy me a coffee](https://img.shields.io/badge/Buy%20me%20a%20coffee-lideke-FFDD00?logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/lideke)
[![CI](https://github.com/EBVN-Tech/churchcrm-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/EBVN-Tech/churchcrm-plugin/actions/workflows/ci.yml)

</div>

---

## Plugins

| Plugin | Id | Purpose |
|---|---|---|
| [Custom Routes](plugins/custom-routes/README.md) | `custom-routes` | custom routes the ChurchCRM API does not provide, for an external application |

Each plugin is installed separately: Admin → Plugins → install from URL, with the URL of
its release zip and its SHA-256. See the plugin's README.

## ☕ Support

These plugins are free and maintained on my own time. If one saved you time, you can buy
me a coffee: it helps keep them tested on new ChurchCRM versions and add what churches ask for.

<a href="https://buymeacoffee.com/lideke"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy me a coffee" height="50"></a>

## Layout

```
plugins/<id>/               a plugin: plugin.json, routes/, src/, README.md, docs/
scripts/build.sh            builds a plugin's zip
scripts/check-plugins.php   checks each plugin's plugin.json
phpstan.neon.dist           static analysis of the plugins
```

Each plugin has its own version, in its `plugin.json`, and its own releases.

## ✍️ Commits

Messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):
each plugin's version depends on them. Write them in English.

| Type | Version bump | Example |
|---|---|---|
| `fix:` | patch (0.8.0 → 0.8.1) | `fix: reject HTML in an event title` |
| `feat:` | minor (0.8.0 → 0.9.0) | `feat: add a route to delete an event` |
| `feat!:` or a `BREAKING CHANGE:` footer | major (0.x: minor) | `feat!: rename the plugin id` |
| `docs:`, `chore:`, `ci:`, `refactor:`, `test:` | none | `docs: clarify 503 errors` |

An optional scope names the plugin: `fix(custom-routes): …`. The version follows the files
touched either way, not the scope.

Only "Squash and merge" is allowed: the PR title becomes the commit message on `main`.
The "PR title" workflow rejects a title that does not follow the format.

## 🚀 Releases

[release-please](https://github.com/googleapis/release-please) handles them, plugin by
plugin (`release-please-config.json`, `.release-please-manifest.json`):

1. On each push to `main`, it opens or updates one release PR per plugin changed by a
   `fix:` or a `feat:`: version bumped in `plugin.json` and the README, `CHANGELOG.md`.
2. Merging that PR creates the `<id>-v<version>` tag and the release.
3. The workflow then builds the zip, attaches it to the release and writes its SHA-256 in
   the notes.

A plugin with no changes gets no release. To add a plugin: add an entry under `packages`
in `release-please-config.json`, and its current version in the manifest
(`scripts/check-plugins.php` rejects a forgotten plugin).

## 📦 Building a zip

```bash
scripts/build.sh <id>
```

The script produces `dist/<id>-<version>.zip`, with a single `<id>` root folder and no
hidden files, README, CHANGELOG or docs, then prints its SHA-256.

## Tests

For each plugin, CI checks:

- PHP 8.4 syntax (`php -l`);
- the `plugin.json` (`scripts/check-plugins.php`): fields required by ChurchCRM, id equal
  to the folder name, `mainClass` and `routesFile` present, release-please entries;
- the code with PHPStan (level 6), against the ChurchCRM 7.8.0 code: unknown class,
  missing import, wrong call;
- ChurchCRM's `plugin-scan.php`, the review scan for community plugins: manifest, file
  types, dangerous functions, outbound hosts, file writes;
- the zip build.

The Release workflow runs the same checks on each release PR's branch and posts the
report on the PR (`scripts/check-report.sh`): CI does not run on PRs opened by
release-please.

Locally:

```bash
scripts/fetch-tools.sh /tmp/tools    # ChurchCRM, PHPStan, plugin-scan.php, pinned by SHA-256
scripts/check-report.sh /tmp/tools
```

Behavior tests are HTTP calls against a test instance: see each plugin's specification.

## Contributing

Bug reports, feature requests and pull requests: see [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report vulnerabilities privately: see [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE).
