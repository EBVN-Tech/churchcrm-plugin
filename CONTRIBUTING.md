# Contributing

Thanks for using these plugins. This page explains how to report a problem or ask for a
feature so it can be acted on quickly.

## Before opening an issue

- **Security problem?** Do not open an issue. Report it privately: see [SECURITY.md](SECURITY.md).
- **Problem in ChurchCRM itself**, not in a plugin from this repository? Open it on
  [ChurchCRM/CRM](https://github.com/ChurchCRM/CRM/issues).
- **Already reported?** Search the [open and closed issues](https://github.com/EBVN-Tech/churchcrm-plugin/issues?q=is%3Aissue)
  first. If you find yours, add your details to it rather than opening a new one.
- **Latest version?** Check that you run the latest release of the plugin: the fix may
  already be out. Each plugin's version is on Admin → Plugins in ChurchCRM, or in the
  `/ping` response for Custom Routes.

## Opening an issue

Go to [New issue](https://github.com/EBVN-Tech/churchcrm-plugin/issues/new/choose) and
pick a form:

- **Bug report**: something does not work as documented.
- **Feature request**: a route, setting or behavior that is missing.

The forms ask for what is needed to reproduce the problem:

- the plugin and its version, the ChurchCRM version, the PHP version;
- for Custom Routes: the method and route called, the request body, the HTTP status and
  the response body;
- what you expected instead;
- relevant lines from ChurchCRM's logs (`logs/` folder of your installation).

One problem per issue. Write in English if you can.

## Remove private data first

Issues are public. Before you post a request, a response or a log:

- remove API keys (`x-api-key`) and passwords;
- replace names, emails, phone numbers, addresses and other personal data of your members
  with made-up values;
- replace your server's address with `https://<churchcrm>`.

An issue that contains personal data or a key will be edited or deleted.

## Contributing code

Pull requests are welcome. For anything bigger than a small fix, open an issue first so we
can agree on the approach before you write the code.

### Setup

You need Git, PHP 8.4 (or Docker), `zip`, `unzip`, `rsync` and `curl`, plus a ChurchCRM
7.8.0+ **test** instance for behavior tests. Never test write routes on a production
instance: they create, change and delete real records.

```bash
git clone https://github.com/EBVN-Tech/churchcrm-plugin.git
cd churchcrm-plugin
scripts/fetch-tools.sh /tmp/tools    # ChurchCRM, PHPStan, ChurchCRM's plugin-scan.php
```

Without a local PHP 8.4, run the scripts in Docker:

```bash
docker run --rm -v "$PWD":/app -v /tmp/tools:/tools -w /app php:8.4-cli \
  sh -c "apt-get -qq update && apt-get -qq install -y git zip unzip rsync >/dev/null \
    && git config --global --add safe.directory /app && scripts/check-report.sh /tools"
```

### Layout

```
plugins/<id>/plugin.json     manifest: id, version, settings (read by ChurchCRM)
plugins/<id>/src/            PHP classes, namespace ChurchCRM\Plugins\<Name>
plugins/<id>/routes/         Slim routes, under /plugins/<id>/
plugins/<id>/docs/SPEC.md    the specification: routes, fields, errors, tests
scripts/                     build, checks, review tools
```

### Rules for plugin code

- **ChurchCRM first.** Use ChurchCRM's models (Propel), permissions and helpers. Write raw
  SQL only where no model exists, always as a prepared statement with bound values.
- **Permissions.** Every write checks the permission of the API key's account
  (AddRecords, EditRecords, Add Event…) and, for custom fields, each field's security.
- **No new capability without discussion**: outbound network calls, file writes, stored
  secrets, hooks or cron change the plugin's risk level in ChurchCRM's registry.
- **Errors.** Return a clear HTTP status (`400`, `403`, `404`, `409`, `422`, `503`) and an
  English message; field errors go in `fields`. Never put a received value or an exception
  message in a response or a log.
- **Spec and docs.** A change in a route, field or error updates `docs/SPEC.md` and, if
  visible to users, the plugin's README, in the same pull request.
- **Style.** PHP 8.4, English code and comments, the style of the surrounding code.
- **Version.** Do not change `version` in `plugin.json`: release-please does it.

### Checks

Run them before pushing:

```bash
scripts/check-report.sh /tmp/tools
```

It runs PHP syntax, the `plugin.json` checks, PHPStan level 6 against ChurchCRM 7.8.0,
ChurchCRM's `plugin-scan.php` and the zip build. CI runs the same checks on your pull
request; all must pass.

Then test the behavior on your test instance: copy the `<id>` folder from the zip in
`dist/` into ChurchCRM's `plugins/community/` folder (the URL installer needs an HTTPS
URL), enable the plugin in Admin → Plugins and call the routes you changed. List what you
tested in the pull request.

### Pull request

- One change per pull request.
- The **PR title** follows [Conventional Commits](README.md#%EF%B8%8F-commits), in English: it
  becomes the commit message on `main` (squash merge) and decides the version bump. A
  check rejects titles that do not follow the format.
- Describe what changed, why, and how you tested it.

### Adding a plugin

Discuss it in an issue first. Then: a `plugins/<id>/` folder whose name is the plugin id,
`"type": "community"` in `plugin.json`, an entry in `release-please-config.json` and in
`.release-please-manifest.json`, a README and a `docs/SPEC.md`. `scripts/check-plugins.php`
tells you what is missing.

## License

By contributing, you agree that your contribution is released under the
[MIT license](LICENSE) of this repository.
