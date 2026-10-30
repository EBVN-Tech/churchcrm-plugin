# Route specification — Custom Routes 0.9.0

Target: ChurchCRM 7.8.0. Route base: `/plugins/custom-routes`.

## Principle

The plugin only adds what the ChurchCRM API cannot do:
list all persons and families, create and update them, read
and write their custom fields. Everything else goes through the existing API (see "Outside the plugin").

Writes go through the Propel models (`Person`, `Family`). Their
`save()` already creates the history notes (`create`, `edit`) and fires
the `person.created`, `person.updated`, `family.created`,
`family.updated` hooks. The plugin never writes SQL into `person_per` or
`family_fam`.

The calling application decides itself between creation and update:
it keeps the ChurchCRM ID returned on creation. No known
ID: `POST`. Known ID: `PATCH`. The plugin never tries to
guess a match (by email or by name).

## Authentication and permissions

- `x-api-key` header, checked by ChurchCRM's `AuthMiddleware`.
- Permissions are those of the key's ChurchCRM account:

| Route | Required permission |
|---|---|
| `GET /persons`, `GET /families` | none beyond a valid key (ChurchCRM gives read access to records to every account) |
| `POST /person`, `POST /family` | AddRecords |
| `PATCH /person/{id}`, `PATCH /family/{id}` | EditRecords |
| `GET /person/{id}/custom`, `GET /family/{id}/custom` | security of each field |
| `PATCH /person/{id}/custom`, `PATCH /family/{id}/custom` | EditRecords, plus the security of each field |
| `PATCH /person/{id}/properties` | EditRecords |
| `GET /lists/{id}` | none beyond a valid key |
| `GET /events/{id}/attendance` | none beyond a valid key |
| `POST /events`, `PATCH /events/{id}`, `DELETE /events/{id}` | AddEvent |

The security of a custom field (`custom_FieldSec`) is an option
number from ChurchCRM list 5 (`1` bAll, `2` bAdmin, `3` bAddRecords,
`4` bEditRecords, `8` bFinance…). The plugin translates it into a name before
`User::isEnabledSecurity()`, as ChurchCRM's pages do.

`GET /api/person/{id}` in ChurchCRM 7.8.0 does not do this translation
(`Person::getVisibleCustomFieldDefinitions()` passes the raw number): a
non-administrator account sees no custom field there. Hence the plugin's
`GET …/custom` route.

A field the account cannot see cannot be written either:
`403`, with nothing saved.

Records are saved under the service account's name (`EnteredBy`,
`EditedBy`). The calling application keeps its own history of who
did what.

## Plugin settings

| Key | Default | Purpose |
|---|---|---|
| `personExternalIdField` | `ext-id` | Name of the person custom field that holds the external ID |
| `familyExternalIdField` | `ext-id` | Name of the family custom field that holds the external ID |
| `excludedGroupName` | empty | Exact name of a ChurchCRM group whose members (technical accounts…) are never returned or editable |

Both fields must be created in ChurchCRM (type "Text Field (50 char)"),
with Admin security so that they stay hidden. The plugin finds them
by name; the name must be unique. Otherwise `isConfigured()` returns
`false` and the routes that depend on them answer `503`. Renaming the field
in ChurchCRM gives the same `503`: no write without an external
ID.

Excluded group: its members appear in no list, and any route
that targets them by their `id` answers `404`. A family whose members
are all excluded is not listed. If the configured group does not exist (or if two
groups have that name), the routes answer `503` rather than returning
records that should have been hidden. Settings are erased when the
plugin is uninstalled: enter them again after each upgrade.

## Idempotent creation

`POST /person` and `POST /family` require an `externalId`: 1 to 50
characters from `A-Z a-z 0-9 _ -` (a UUID works).

- An existing record already has this `externalId`: `200` with its `id`,
  nothing is changed.
- Otherwise: creation, `externalId` written in the same transaction,
  `201` with the new `id`.

Retrying a `POST` after a network failure therefore does not create a duplicate. Known
limit: two simultaneous `POST`s with the same `externalId` can create two
records. The calling application sends its creations one at a time.

## Routes

### `GET /ping`

Since 0.7.1, the response adds `doNotEmailPropertyId` and `doNotSmsPropertyId` (integer, or `null` if not set).
Since 0.9.0, it adds `noteEdit: true`: a calling application can turn a feature on as soon as
the plugin is installed, without comparing version numbers or probing a route for a `404`.

Checks the installation: `version`, `userId`, `addRecords`, `editRecords`,
`configured`, `configurationError`, `excludedGroup` (configured name) and
`excludedPersons` (number of hidden persons).

`userId` is the ChurchCRM user behind the API key. It tells the calling application
which notes it wrote itself, hence which ones the ChurchCRM API lets it edit
(see `PATCH /person/{id}/note/{noteId}`).

### `GET /persons`

All persons excluding the excluded group, sorted by last name then first name. No
pagination: a few hundred records fit in one response. No
custom field: the IBAN is read record by record via `…/custom`.

```json
{ "success": true, "count": 1, "items": [ {
  "id": 12, "firstName": "Leah", "lastName": "Moore", "familyId": 3,
  "familyRoleId": 3, "classificationId": 1, "active": true,
  "email": null, "cellPhone": null,
  "birthDay": 2, "birthMonth": 5, "birthYear": 2019,
  "doNotEmail": false, "doNotSms": false
} ] }
```

`doNotEmail` / `doNotSms`: the person has the property set in
ChurchCRM's `iDoNotEmailPropertyId` / `iDoNotSmsPropertyId` (`false` if the
setting is empty).

### `GET /families`

All families, sorted by name, with `memberCount` (excluding the excluded group):
`id`, `name`, `address1`, `address2`, `zip`, `city`, `homePhone`, `email`,
`active`, `memberCount`.

### `POST /person`

JSON body. `externalId`, `firstName` and `lastName` required. Without
`country`, the default country from the ChurchCRM settings is applied, as
in its editors.

Response `201` (or `200` if the `externalId` already exists):

```json
{ "success": true, "id": 123, "created": true }
```

### `PATCH /person/{id}`

JSON body, all fields optional, at least one. Only the fields
present change. `null` clears a field (`0` for integer fields), except
for required fields. `externalId` cannot be changed. `404` if the
person does not exist.

Response `200`: `{ "success": true, "id": 123 }`.

### Person fields

| JSON field | Column | Rule |
|---|---|---|
| `title` | `per_Title` | text, 50 max |
| `firstName` | `per_FirstName` | text, 50 max, non-empty |
| `middleName` | `per_MiddleName` | text, 50 max |
| `lastName` | `per_LastName` | text, 50 max, non-empty |
| `suffix` | `per_Suffix` | text, 50 max |
| `gender` | `per_Gender` | `0` unknown, `1` male, `2` female |
| `birthDay` | `per_BirthDay` | 0 to 31 |
| `birthMonth` | `per_BirthMonth` | 0 to 12 |
| `birthYear` | `per_BirthYear` | 1900 to the current year, or `null` |
| `email` | `per_Email` | valid email, 50 max |
| `workEmail` | `per_WorkEmail` | valid email, 50 max |
| `cellPhone` | `per_CellPhone` | text, 30 max |
| `homePhone` | `per_HomePhone` | text, 30 max |
| `workPhone` | `per_WorkPhone` | text, 30 max |
| `address1`, `address2` | `per_Address1`, `per_Address2` | text, 50 max |
| `city`, `state`, `zip`, `country` | `per_City`… | text, 50 max |
| `familyId` | `per_fam_ID` | existing family, or `0` for no family |
| `familyRoleId` | `per_fmr_ID` | `0` or existing option of list 2 (family role) |
| `classificationId` | `per_cls_ID` | `0` or existing option of list 1 (classification) |
| `membershipDate` | `per_MembershipDate` | `YYYY-MM-DD` or `null` |
| `friendDate` | `per_FriendDate` | `YYYY-MM-DD` or `null` |

Texts: leading and trailing spaces removed, HTML tags rejected. An
impossible birth day (February 31…) is rejected when day and month
are sent together.

The labels of lists 1 and 2 are set in ChurchCRM. The calling
application does its own mapping (for example status "member" →
classification `1`).

### `POST /family` and `PATCH /family/{id}`

Same rules as for persons. `externalId` and `name` required on
creation.

| JSON field | Column | Rule |
|---|---|---|
| `name` | `fam_Name` | text, 50 max, non-empty |
| `address1`, `address2` | `fam_Address1`, `fam_Address2` | text, 255 max |
| `city`, `state`, `zip`, `country` | `fam_City`… | text, 50 max |
| `homePhone` | `fam_HomePhone` | text, 30 max |
| `email` | `fam_Email` | valid email, 100 max |
| `weddingDate` | `fam_WeddingDate` | `YYYY-MM-DD` or `null` |

### `PATCH /person/{id}/note/{noteId}`

Since 0.9.0. Replaces the text of a **public** note on a person's record,
whoever wrote it. The ChurchCRM API (`PUT /api/note/{id}`) only lets the
author of a note or an administrator edit it: a calling application using a
service account can therefore never correct, through the API, a note typed in
ChurchCRM itself. Here EditRecords is enough, as for other record changes.

JSON body: `{ "text": "<p>…</p>" }`. HTML, like the note routes of the
ChurchCRM API. **The previous text is lost**: ChurchCRM keeps no history of a
note, only the date and the author of the last edit.

- `404`: unknown or excluded person, note that does not exist, that belongs to
  another person, or that is private. Private notes are never returned by the
  API to a non-author either: the plugin does not open them.
- `422`: missing or empty `text`, unknown field.

Response `200`: `{ "success": true, "id": 123, "noteId": 45 }`. The note's
`DateLastEdited` / `EditedBy` are set, and so are the record's.

### `PATCH /person/{id}/properties`

Sets or removes the "Do not contact" properties set in ChurchCRM
(Admin → Email, `iDoNotEmailPropertyId` and `iDoNotSmsPropertyId`). The equivalent
ChurchCRM API (`/api/people/properties/…`) requires the "Menu
Options" permission; here EditRecords is enough.

JSON body: `{ "doNotEmail": true }`, `{ "doNotSms": false }` or both.
Boolean values only. Idempotent: setting a property already set or
removing an absent property changes nothing.

- `422`: unknown field, non-boolean value, empty body.
- `503`: the requested property is not set in ChurchCRM, or the configured
  id does not point to a person property.

Response `200`: `{ "success": true, "id": 123, "updated": ["doNotEmail"] }`.
The record's `DateLastEdited` / `EditedBy` are updated.

### `GET /lists/{id}`

Options of a ChurchCRM list, sorted by display order: `1`
classification (`per_cls_ID`), `2` family role (`per_fmr_ID`)… Used by
the external application to check its mappings.

```json
{ "success": true, "listId": 2, "count": 4, "items": [
  { "id": 1, "name": "Head of Household", "sequence": 1 }, { "id": 2, "name": "Spouse", "sequence": 2 } ] }
```

`404` if the list does not exist or is empty.

### `POST /events`, `PATCH /events/{id}`, `DELETE /events/{id}`

Calendar events (`events_event`). The ChurchCRM 7.8.0 API does not return
the ID of a created event and requires extra fields; these
routes do the minimum with the "Add Event" permission.

Fields: `title` (255, non-empty), `typeId` (existing type), `start` and `end`
(`YYYY-MM-DD HH:MM:SS`, end after start), `desc` (255), `text`, `active`
(boolean). Creation: the first four are required. Update:
only the fields sent. No HTML in `title`, `desc` and `text`.

- `POST` → `201 { "success": true, "id": 12 }`
- `PATCH` → `200 { "success": true, "id": 12 }`
- `DELETE` → `200 { "success": true, "id": 12, "deleted": true }`; `409` if
  persons are still checked in or a kiosk uses the event.
- `403` without "Add Event", `404` not found, `422` validation.

`/ping` reports `addEvent` since 0.7.1.

### `GET /events/{id}/attendance`

Attendance recorded on an event (`event_attend`), excluding the excluded group.
ChurchCRM's `GET /api/events/{id}/roster` only returns the members of the
groups linked to the event; this route returns all persons
checked in, for example by `POST /api/events/{id}/checkin` ("Add
Event" permission in ChurchCRM, Events module enabled).

```json
{ "success": true,
  "event": { "id": 7, "title": "Sunday service", "start": "2026-10-18 10:30:00", "end": "2026-10-18 12:00:00", "active": true },
  "count": 1, "items": [ { "personId": 12, "checkinDate": "2026-10-18 10:32:10", "checkoutDate": null } ] }
```

`404` if the event does not exist.

### `GET /person/{id}/custom` and `GET /family/{id}/custom`

Fields the account is allowed to see, except the external ID:

```json
{ "success": true, "id": 123, "fields": { "c4": { "name": "IBAN", "value": "FR76…" } } }
```

### `PATCH /person/{id}/custom` and `PATCH /family/{id}/custom`

JSON body: field codes → value, for example
`{ "c4": "FR76…", "c5": "John Smith" }`. `null` or `""` clears the field.

| ChurchCRM type | JSON value |
|---|---|
| 1 True / false | `true` or `false` |
| 2 Date | `"YYYY-MM-DD"` |
| 3, 4, 5 Text 50 / 100 / long | text, max length of the type, no HTML tag |
| 6 Year | 4-digit integer |
| 8 Number | integer |
| 10 Money | number, rounded to 2 decimals |
| 11 Phone | text, 30 max |
| 12 Drop-down list | integer, existing option of the field's list |
| 7 Season, 9 Person from a group | rejected in 0.3.0 |

- Unknown code or external ID field: `422`.
- Written with `INSERT … ON DUPLICATE KEY UPDATE` on `person_custom` /
  `family_custom`, like ChurchCRM's CSV import, then `DateLastEdited` /
  `EditedBy` updated on the record, in the same transaction.

Response `200`: `{ "success": true, "id": 123, "updated": ["c4", "c5"] }`.

## Errors

Body: `{ "success": false, "error": "…", "fields": { "email": "invalid format" } }`
(`fields` only when the error is about fields).

| Code | Case |
|---|---|
| `400` | Missing body, unreadable JSON, or array instead of an object |
| `401` | Invalid key (handled by ChurchCRM) |
| `302` | Missing key: ChurchCRM redirects to its login page. The client does not follow redirects and treats this case as an authentication failure |
| `403` | Missing permission, on the route or on a custom field |
| `404` | Person or family not found |
| `422` | Validation, unknown field, no field sent |
| `500` | Internal error (`"Internal error"`, details in the ChurchCRM log) |
| `503` | Plugin not configured (external ID field missing or duplicated) |

An unknown JSON field gives `422`: a typo must not go through
silently.

## Sensitive data

- Responses and error messages never return a received value,
  only field names. Only exception: `GET …/custom`, which
  returns the values the account is allowed to read.
- The plugin's logs only contain the route, the method and the
  exception type with its file and line. Never a value nor the message
  of an exception (an SQL error may quote a value).
- Sensitive values (IBAN…) go in the body, never in the URL:
  ChurchCRM logs the paths.

## Outside the plugin: existing ChurchCRM API

| Need | Route |
|---|---|
| Activate or deactivate a record | `POST /api/person/{id}/activate/{true\|false}`, same for `family` |
| Email / SMS contact opt-out | Since 0.7.1: the plugin's `PATCH /person/{id}/properties`. The ChurchCRM API `POST`/`DELETE /api/people/properties/person/{id}/{propertyId}` requires the "Menu Options" permission |
| Read a record | `GET /api/person/{id}`, `GET /api/family/{id}` |
| Delete | `DELETE /api/person/{id}`, `DELETE /api/family/{id}` |
| Read and add a note, photo | existing `note` and `photo` routes. `GET /api/person/{id}/notes` returns `enteredBy` for each note: the ChurchCRM user who wrote it |
| Edit a note | Since 0.9.0: the plugin's `PATCH /person/{id}/note/{noteId}`. The ChurchCRM API `PUT /api/note/{id}` only accepts the author of the note or an administrator |

Do not deactivate the person record linked to the service account: ChurchCRM
then blocks its API key.

## Upgrading the plugin

The ChurchCRM installer never overwrites an installed plugin: uninstall
the old version (Admin → Plugins), then install the new one from its URL
with its SHA-256. Uninstalling erases the plugin settings.

Since 0.8.0, the plugin is called Custom Routes: id `custom-routes`, routes
under `/plugins/custom-routes`. The old id `people-sync` is a different
plugin for ChurchCRM: install `custom-routes`, enter its settings again,
change the base URL in the calling application, then uninstall
`people-sync`.

## Tests

Syntax: `php -l` with PHP 8.4 (version required by ChurchCRM 7.8.0), for
example with `docker run --rm -v "$PWD":/app -w /app php:8.4-cli php -l …`.

Behavior: HTTP calls on the instance, before any import of real
data, with test records deleted afterwards. One scenario per row
of the "Errors" and "Idempotent creation" tables, plus a
`POST` → `PATCH` → `GET` round trip per route.
