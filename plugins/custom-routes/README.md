<div align="center">

# 🧩 Custom Routes

**ChurchCRM plugin: the custom routes the API does not provide.**

Persons, families, custom fields, events and attendance, under
`/plugins/custom-routes/`, for an external application (intranet, sync tool).
A route is added when a need arises.

![Version](https://img.shields.io/static/v1?label=version&message=0.8.1&color=blue) <!-- x-release-please-version -->
![ChurchCRM](https://img.shields.io/badge/ChurchCRM-%E2%89%A5%207.8.0-2a6db0)
![PHP](https://img.shields.io/badge/PHP-8.4-777bb4)
[![Release](https://img.shields.io/github/v/release/EBVN-Tech/churchcrm-plugin?filter=custom-routes-*)](https://github.com/EBVN-Tech/churchcrm-plugin/releases?q=custom-routes)

[Quick start](#-quick-start) · [Routes](#-routes) · [Settings](#%EF%B8%8F-settings) · [Upgrade](#-upgrade) · [Build the zip](#-build-the-zip) · [Specification](docs/SPEC.md)

</div>

---

## Why

The ChurchCRM 7.8.0 API reads records, but cannot list them all, create them,
or update them from an external application. Custom Routes adds what is missing, as needs arise:

| | |
|---|---|
| 🔁 **Idempotent creation** | `POST` requires an `externalId`: retrying after a network failure does not create a duplicate. |
| ✏️ **Partial update** | `PATCH` only writes the fields sent. An unknown field gives `422`, never a silent omission. |
| 🔐 **ChurchCRM permissions** | `x-api-key` key, permissions of the key's account, security of each custom field. |
| 🙈 **Excluded group** | Technical accounts in a configured group are never returned or editable. |
| 🧾 **History kept** | Writes go through the Propel models: ChurchCRM history notes and hooks included, never direct SQL. |

## 🚀 Quick start

**1. Create the external ID fields.** In ChurchCRM, a custom field named
`ext-id` ("Text Field (50 char)", Admin security) for persons, and one for families.

**2. Install.** Admin → Plugins → install from URL, with:

- the zip URL of the [latest `custom-routes-v…` release](https://github.com/EBVN-Tech/churchcrm-plugin/releases?q=custom-routes);
- its SHA-256, shown in the release;
- the id `custom-routes`.

ChurchCRM marks the plugin "unverified": this is normal for a plugin that is not in the official registry.

**3. Check**, with the service account's API key:

```bash
curl -H "x-api-key: <key>" https://<churchcrm>/plugins/custom-routes/ping
```

## 🧭 Routes

Base: `/plugins/custom-routes`. Fields, responses and errors in detail: [`docs/SPEC.md`](docs/SPEC.md).

| Method | Route | Purpose | Required permission |
|---|---|---|---|
| `GET` | `/ping` | checks the installation and settings | valid key |
| `GET` | `/{persons,families}` | full lists, excluding the excluded group | valid key |
| `POST` | `/{person,family}` | creates a record (idempotent by `externalId`) | AddRecords |
| `PATCH` | `/{person,family}/{id}` | updates the fields sent | EditRecords |
| `GET` | `/{person,family}/{id}/custom` | reads the allowed custom fields | security of each field |
| `PATCH` | `/{person,family}/{id}/custom` | writes custom fields | EditRecords + security of each field |
| `PATCH` | `/person/{id}/properties` | sets or removes "Do not contact" (email, SMS) | EditRecords |
| `GET` | `/lists/{id}` | options of a ChurchCRM list (classification, family role…) | valid key |
| `POST` | `/events` | creates an event | Add Event |
| `PATCH` | `/events/{id}` | updates an event | Add Event |
| `DELETE` | `/events/{id}` | deletes an event with no attendance | Add Event |
| `GET` | `/events/{id}/attendance` | attendance of an event, excluding the excluded group | valid key |

Reading, activating or deleting a record, notes and photo stay in the existing ChurchCRM
API (see "Outside the plugin" in the [specification](docs/SPEC.md#outside-the-plugin-existing-churchcrm-api)).

## ⚙️ Settings

| Key | Default | Purpose |
|---|---|---|
| `personExternalIdField` | `ext-id` | person custom field that holds the external ID |
| `familyExternalIdField` | `ext-id` | family custom field that holds the external ID |
| `excludedGroupName` | empty | group whose members are never returned or editable |

A field or group that is not found, or duplicated, makes the routes that depend on it
answer `503`: no write without an external ID, no record that should have been hidden.

## 🔼 Upgrade

The ChurchCRM installer never overwrites an installed plugin: uninstall the old
version, then install the new one from its URL with its SHA-256.

> ⚠️ Uninstalling erases the plugin settings: enter them again after each upgrade.

**Upgrade from `people-sync` (0.7.1 and earlier).** The plugin used to be called People Sync. The new id
makes `custom-routes` a different plugin for ChurchCRM, and changes the base URL of the routes:

1. install `custom-routes` and enter its settings again;
2. replace `/plugins/people-sync` with `/plugins/custom-routes` in the calling application;
3. uninstall `people-sync`.

## 📦 Build the zip

From the repository root:

```bash
scripts/build.sh custom-routes
```

The script produces `dist/custom-routes-<version>.zip` (a single `custom-routes` root folder,
no hidden files) and prints its SHA-256. Releases are automatic: see
["Releases"](../../README.md#-releases) at the repository root.

Tests: `php -l` with PHP 8.4, then HTTP calls on a test instance (see
"Tests" in the [specification](docs/SPEC.md#tests)).
