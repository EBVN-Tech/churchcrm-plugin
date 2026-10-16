# Spécification des routes — People Sync 0.7.1

Cible : ChurchCRM 7.8.0. Base des routes : `/plugins/people-sync`.

## Principe

Le plugin ajoute seulement ce que l'API ChurchCRM ne sait pas faire :
lister toutes les personnes et familles, les créer et les modifier, lire
et écrire leurs champs personnalisés. Tout le reste passe par l'API existante (voir « Hors plugin »).

Les écritures passent par les modèles Propel (`Person`, `Family`). Leur
`save()` crée déjà les notes d'historique (`create`, `edit`) et déclenche
les hooks `person.created`, `person.updated`, `family.created`,
`family.updated`. Le plugin n'écrit jamais en SQL dans `person_per` ou
`family_fam`.

L'application appelante décide elle-même entre création et modification :
elle garde l'identifiant ChurchCRM renvoyé à la création. Pas d'identifiant
connu : `POST`. Identifiant connu : `PATCH`. Le plugin ne cherche jamais à
deviner une correspondance (par e-mail ou par nom).

## Authentification et droits

- En-tête `x-api-key`, vérifié par l'`AuthMiddleware` de ChurchCRM.
- Les droits sont ceux du compte ChurchCRM de la clé :

| Route | Droit exigé |
|---|---|
| `GET /persons`, `GET /families` | aucun au-delà d'une clé valide (ChurchCRM donne la lecture des fiches à tout compte) |
| `POST /person`, `POST /family` | AddRecords |
| `PATCH /person/{id}`, `PATCH /family/{id}` | EditRecords |
| `GET /person/{id}/custom`, `GET /family/{id}/custom` | sécurité de chaque champ |
| `PATCH /person/{id}/custom`, `PATCH /family/{id}/custom` | EditRecords, plus la sécurité de chaque champ |
| `PATCH /person/{id}/properties` | EditRecords |
| `GET /lists/{id}` | aucun au-delà d'une clé valide |
| `GET /events/{id}/attendance` | aucun au-delà d'une clé valide |
| `POST /events`, `PATCH /events/{id}`, `DELETE /events/{id}` | AddEvent |

La sécurité d'un champ personnalisé (`custom_FieldSec`) est un numéro
d'option de la liste ChurchCRM 5 (`1` bAll, `2` bAdmin, `3` bAddRecords,
`4` bEditRecords, `8` bFinance…). Le plugin le traduit en nom avant
`User::isEnabledSecurity()`, comme les pages de ChurchCRM.

`GET /api/person/{id}` de ChurchCRM 7.8.0 ne fait pas cette traduction
(`Person::getVisibleCustomFieldDefinitions()` passe le numéro brut) : un
compte non administrateur n'y voit aucun champ personnalisé. D'où la route
`GET …/custom` du plugin.

Un champ que le compte ne peut pas voir ne peut pas non plus être écrit :
`403`, sans rien enregistrer.

Les fiches sont enregistrées au nom du compte de service (`EnteredBy`,
`EditedBy`). L'application appelante garde son propre historique de qui a
fait quoi.

## Réglages du plugin

| Clé | Par défaut | Rôle |
|---|---|---|
| `personExternalIdField` | `ext-id` | Nom du champ personnalisé de personne qui reçoit l'identifiant externe |
| `familyExternalIdField` | `ext-id` | Nom du champ personnalisé de famille qui reçoit l'identifiant externe |
| `excludedGroupName` | vide | Nom exact d'un groupe ChurchCRM dont les membres (comptes techniques…) ne sont jamais renvoyés ni modifiables |

Les deux champs sont à créer dans ChurchCRM (type « Text Field (50 char) »),
avec une sécurité Admin pour qu'ils restent masqués. Le plugin les retrouve
par leur nom ; le nom doit être unique. Sinon `isConfigured()` renvoie
`false` et les routes qui en dépendent répondent `503`. Renommer le champ
dans ChurchCRM produit le même `503` : pas d'écriture sans identifiant
externe.

Groupe exclu : ses membres n'apparaissent dans aucune liste, et toute route
qui les vise par leur `id` répond `404`. Une famille dont tous les membres
sont exclus n'est pas listée. Si le groupe réglé n'existe pas (ou si deux
groupes portent ce nom), les routes répondent `503` plutôt que de renvoyer
des fiches qui auraient dû être masquées. Les réglages sont effacés à la
désinstallation du plugin : les ressaisir après chaque mise à jour.

## Idempotence de la création

`POST /person` et `POST /family` exigent un `externalId` : 1 à 50
caractères parmi `A-Z a-z 0-9 _ -` (un UUID convient).

- Une fiche existante porte déjà cet `externalId` : `200` avec son `id`,
  rien n'est modifié.
- Sinon : création, écriture de l'`externalId` dans la même transaction,
  `201` avec le nouvel `id`.

Relancer un `POST` après une coupure ne crée donc pas de doublon. Limite
connue : deux `POST` simultanés avec le même `externalId` peuvent créer deux
fiches. L'application appelante envoie ses créations une par une.

## Routes

### `GET /ping`

Depuis 0.7.1, la réponse ajoute `doNotEmailPropertyId` et `doNotSmsPropertyId` (entier ou `null` si non réglé).

Vérifie l'installation : `version`, `userId`, `addRecords`, `editRecords`,
`configured`, `configurationError`, `excludedGroup` (nom réglé) et
`excludedPersons` (nombre de personnes masquées).

### `GET /persons`

Toutes les personnes hors groupe exclu, triées par nom puis prénom. Pas de
pagination : quelques centaines de fiches tiennent dans une réponse. Pas de
champ personnalisé : l'IBAN se lit fiche par fiche via `…/custom`.

```json
{ "success": true, "count": 1, "items": [ {
  "id": 12, "firstName": "Léa", "lastName": "Moreau", "familyId": 3,
  "familyRoleId": 3, "classificationId": 1, "active": true,
  "email": null, "cellPhone": null,
  "birthDay": 2, "birthMonth": 5, "birthYear": 2019,
  "doNotEmail": false, "doNotSms": false
} ] }
```

`doNotEmail` / `doNotSms` : la personne porte la propriété réglée dans
`iDoNotEmailPropertyId` / `iDoNotSmsPropertyId` de ChurchCRM (`false` si le
réglage est vide).

### `GET /families`

Toutes les familles, triées par nom, avec `memberCount` (hors groupe exclu) :
`id`, `name`, `address1`, `address2`, `zip`, `city`, `homePhone`, `email`,
`active`, `memberCount`.

### `POST /person`

Corps JSON. `externalId`, `firstName` et `lastName` obligatoires. Sans
`country`, le pays par défaut des réglages ChurchCRM est appliqué, comme
dans ses éditeurs.

Réponse `201` (ou `200` si l'`externalId` existe déjà) :

```json
{ "success": true, "id": 123, "created": true }
```

### `PATCH /person/{id}`

Corps JSON, tous les champs facultatifs, au moins un. Seuls les champs
présents changent. `null` vide un champ (`0` pour les champs entiers), sauf
pour les champs obligatoires. `externalId` ne se modifie pas. `404` si la
personne n'existe pas.

Réponse `200` : `{ "success": true, "id": 123 }`.

### Champs de personne

| Champ JSON | Colonne | Règle |
|---|---|---|
| `title` | `per_Title` | texte, 50 max |
| `firstName` | `per_FirstName` | texte, 50 max, non vide |
| `middleName` | `per_MiddleName` | texte, 50 max |
| `lastName` | `per_LastName` | texte, 50 max, non vide |
| `suffix` | `per_Suffix` | texte, 50 max |
| `gender` | `per_Gender` | `0` inconnu, `1` homme, `2` femme |
| `birthDay` | `per_BirthDay` | 0 à 31 |
| `birthMonth` | `per_BirthMonth` | 0 à 12 |
| `birthYear` | `per_BirthYear` | 1900 à l'année en cours, ou `null` |
| `email` | `per_Email` | e-mail valide, 50 max |
| `workEmail` | `per_WorkEmail` | e-mail valide, 50 max |
| `cellPhone` | `per_CellPhone` | texte, 30 max |
| `homePhone` | `per_HomePhone` | texte, 30 max |
| `workPhone` | `per_WorkPhone` | texte, 30 max |
| `address1`, `address2` | `per_Address1`, `per_Address2` | texte, 50 max |
| `city`, `state`, `zip`, `country` | `per_City`… | texte, 50 max |
| `familyId` | `per_fam_ID` | famille existante, ou `0` sans famille |
| `familyRoleId` | `per_fmr_ID` | `0` ou option existante de la liste 2 (rôle familial) |
| `classificationId` | `per_cls_ID` | `0` ou option existante de la liste 1 (classification) |
| `membershipDate` | `per_MembershipDate` | `AAAA-MM-JJ` ou `null` |
| `friendDate` | `per_FriendDate` | `AAAA-MM-JJ` ou `null` |

Textes : espaces de début et de fin retirés, balises HTML refusées. Un
jour de naissance impossible (31 février…) est refusé quand jour et mois
sont envoyés ensemble.

Les libellés des listes 1 et 2 se règlent dans ChurchCRM. L'application
appelante fait sa propre correspondance (par exemple statut « membre » →
classification `1`).

### `POST /family` et `PATCH /family/{id}`

Mêmes règles que pour les personnes. `externalId` et `name` obligatoires à
la création.

| Champ JSON | Colonne | Règle |
|---|---|---|
| `name` | `fam_Name` | texte, 50 max, non vide |
| `address1`, `address2` | `fam_Address1`, `fam_Address2` | texte, 255 max |
| `city`, `state`, `zip`, `country` | `fam_City`… | texte, 50 max |
| `homePhone` | `fam_HomePhone` | texte, 30 max |
| `email` | `fam_Email` | e-mail valide, 100 max |
| `weddingDate` | `fam_WeddingDate` | `AAAA-MM-JJ` ou `null` |

### `PATCH /person/{id}/properties`

Pose ou retire les propriétés « Ne pas contacter » réglées dans ChurchCRM
(Admin → E-mail, `iDoNotEmailPropertyId` et `iDoNotSmsPropertyId`). L'API
ChurchCRM équivalente (`/api/people/properties/…`) exige le droit « Menu
Options » ; ici EditRecords suffit.

Corps JSON : `{ "doNotEmail": true }`, `{ "doNotSms": false }` ou les deux.
Valeurs booléennes seulement. Idempotent : poser une propriété déjà posée ou
retirer une propriété absente ne change rien.

- `422` : champ inconnu, valeur non booléenne, corps vide.
- `503` : la propriété demandée n'est pas réglée dans ChurchCRM, ou l'id
  réglé ne désigne pas une propriété de personne.

Réponse `200` : `{ "success": true, "id": 123, "updated": ["doNotEmail"] }`.
`DateLastEdited` / `EditedBy` de la fiche sont mis à jour.

### `GET /lists/{id}`

Options d'une liste ChurchCRM, triées par ordre d'affichage : `1`
classification (`per_cls_ID`), `2` rôle familial (`per_fmr_ID`)… Sert à
l'application externe pour vérifier ses correspondances.

```json
{ "success": true, "listId": 2, "count": 4, "items": [
  { "id": 1, "name": "Head of Household", "sequence": 1 }, { "id": 2, "name": "Spouse", "sequence": 2 } ] }
```

`404` si la liste n'existe pas ou est vide.

### `POST /events`, `PATCH /events/{id}`, `DELETE /events/{id}`

Événements du calendrier (`events_event`). L'API ChurchCRM 7.8.0 ne renvoie
pas l'identifiant d'un événement créé et exige des champs annexes ; ces
routes font le minimum avec le droit « Add Event ».

Champs : `title` (255, non vide), `typeId` (type existant), `start` et `end`
(`AAAA-MM-JJ HH:MM:SS`, fin après début), `desc` (255), `text`, `active`
(booléen). Création : les quatre premiers obligatoires. Modification :
seulement les champs envoyés. Pas de HTML dans `title`, `desc` et `text`.

- `POST` → `201 { "success": true, "id": 12 }`
- `PATCH` → `200 { "success": true, "id": 12 }`
- `DELETE` → `200 { "success": true, "id": 12, "deleted": true }` ; `409` si des
  personnes sont encore pointées ou qu'un kiosque utilise l'événement.
- `403` sans « Add Event », `404` introuvable, `422` validation.

`/ping` indique `addEvent` depuis 0.7.1.

### `GET /events/{id}/attendance`

Présences enregistrées sur un événement (`event_attend`), hors groupe exclu.
`GET /api/events/{id}/roster` de ChurchCRM ne renvoie que les membres des
groupes liés à l'événement ; cette route renvoie toutes les personnes
pointées, par exemple par `POST /api/events/{id}/checkin` (droit « Add
Event » dans ChurchCRM, module Événements activé).

```json
{ "success": true,
  "event": { "id": 7, "title": "Culte", "start": "2026-10-18 10:30:00", "end": "2026-10-18 12:00:00", "active": true },
  "count": 1, "items": [ { "personId": 12, "checkinDate": "2026-10-18 10:32:10", "checkoutDate": null } ] }
```

`404` si l'événement n'existe pas.

### `GET /person/{id}/custom` et `GET /family/{id}/custom`

Champs que le compte a le droit de voir, sauf l'identifiant externe :

```json
{ "success": true, "id": 123, "fields": { "c4": { "name": "IBAN", "value": "FR76…" } } }
```

### `PATCH /person/{id}/custom` et `PATCH /family/{id}/custom`

Corps JSON : codes de champ → valeur, par exemple
`{ "c4": "FR76…", "c5": "Jean Dupont" }`. `null` ou `""` vide le champ.

| Type ChurchCRM | Valeur JSON |
|---|---|
| 1 Vrai / faux | `true` ou `false` |
| 2 Date | `"AAAA-MM-JJ"` |
| 3, 4, 5 Texte 50 / 100 / long | texte, longueur max du type, sans balise HTML |
| 6 Année | entier sur 4 chiffres |
| 8 Nombre | entier |
| 10 Montant | nombre, arrondi à 2 décimales |
| 11 Téléphone | texte, 30 max |
| 12 Liste déroulante | entier, option existante de la liste du champ |
| 7 Saison, 9 Personne d'un groupe | refusés en 0.3.0 |

- Code inconnu ou champ d'identifiant externe : `422`.
- Écriture en `INSERT … ON DUPLICATE KEY UPDATE` sur `person_custom` /
  `family_custom`, comme l'import CSV de ChurchCRM, puis mise à jour de
  `DateLastEdited` / `EditedBy` sur la fiche, dans la même transaction.

Réponse `200` : `{ "success": true, "id": 123, "updated": ["c4", "c5"] }`.

## Erreurs

Corps : `{ "success": false, "error": "…", "fields": { "email": "format invalide" } }`
(`fields` seulement quand l'erreur porte sur des champs).

| Code | Cas |
|---|---|
| `400` | Corps absent, JSON illisible ou tableau au lieu d'un objet |
| `401` | Clé invalide (géré par ChurchCRM) |
| `302` | Clé absente : ChurchCRM redirige vers sa page de connexion. Le client ne suit pas les redirections et traite ce cas comme un échec d'authentification |
| `403` | Droit manquant, sur la route ou sur un champ personnalisé |
| `404` | Personne ou famille introuvable |
| `422` | Validation, champ inconnu, aucun champ envoyé |
| `500` | Erreur interne (`"Erreur interne"`, détails dans le log de ChurchCRM) |
| `503` | Plugin non configuré (champ d'identifiant externe absent ou en double) |

Un champ JSON inconnu donne `422` : une faute de frappe ne doit pas passer
en silence.

## Données sensibles

- Les réponses et messages d'erreur ne renvoient jamais une valeur reçue,
  seulement des noms de champs. Seule exception : `GET …/custom`, qui
  renvoie les valeurs que le compte a le droit de lire.
- Les logs du plugin ne contiennent que la route, la méthode et le type
  d'exception avec son fichier et sa ligne. Jamais une valeur ni le message
  d'une exception (une erreur SQL peut citer une valeur).
- Les valeurs sensibles (IBAN…) passent dans le corps, jamais dans l'URL :
  ChurchCRM journalise les chemins.

## Hors plugin : API ChurchCRM existante

| Besoin | Route |
|---|---|
| Activer ou désactiver une fiche | `POST /api/person/{id}/activate/{true\|false}`, idem `family` |
| Refus de contact e-mail / SMS | Depuis 0.7.1 : `PATCH /person/{id}/properties` du plugin. L'API ChurchCRM `POST`/`DELETE /api/people/properties/person/{id}/{propertyId}` exige le droit « Menu Options » |
| Lire une fiche | `GET /api/person/{id}`, `GET /api/family/{id}` |
| Supprimer | `DELETE /api/person/{id}`, `DELETE /api/family/{id}` |
| Notes, photo | routes `note` et `photo` existantes |

Ne pas désactiver la fiche personne liée au compte de service : ChurchCRM
bloque alors sa clé API.

## Mise à jour du plugin

L'installeur de ChurchCRM n'écrase jamais un plugin installé : désinstaller
l'ancienne version (Admin → Plugins), puis installer la nouvelle par URL
avec son SHA-256. La désinstallation efface les réglages du plugin.

## Tests

Syntaxe : `php -l` en PHP 8.4 (version exigée par ChurchCRM 7.8.0), par
exemple avec `docker run --rm -v "$PWD":/app -w /app php:8.4-cli php -l …`.

Comportement : appels HTTP sur l'instance, avant tout import de données
réelles, avec des fiches de test supprimées ensuite. Un scénario par ligne
des tableaux « Erreurs » et « Idempotence », plus un aller-retour
`POST` → `PATCH` → `GET` par route.
