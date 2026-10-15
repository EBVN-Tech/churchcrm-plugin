# Spécification des routes — People Sync 0.3.0

Cible : ChurchCRM 7.8.0. Base des routes : `/plugins/people-sync`.

## Principe

Le plugin ajoute seulement ce que l'API ChurchCRM ne sait pas faire :
créer et modifier des personnes et des familles, et écrire leurs champs
personnalisés. Tout le reste passe par l'API existante (voir « Hors plugin »).

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
| `POST /person`, `POST /family` | AddRecords |
| `PATCH /person/{id}`, `PATCH /family/{id}` | EditRecords |
| `PATCH /person/{id}/custom`, `PATCH /family/{id}/custom` | EditRecords, plus la sécurité de chaque champ (`custom_FieldSec`, via `User::isEnabledSecurity()`) |

Un champ personnalisé que le compte ne peut pas voir ne peut pas non plus
être écrit : `403`, sans rien enregistrer.

Les fiches sont enregistrées au nom du compte de service (`EnteredBy`,
`EditedBy`). L'application appelante garde son propre historique de qui a
fait quoi.

## Réglages du plugin (`plugin.json` → `settings`)

| Clé | Exemple | Rôle |
|---|---|---|
| `personExternalIdField` | `c1` | Champ personnalisé de personne qui reçoit l'identifiant externe |
| `familyExternalIdField` | `c1` | Champ personnalisé de famille qui reçoit l'identifiant externe |

Les deux champs sont à créer dans ChurchCRM (type « Text Field (50 char) »),
de préférence avec une sécurité Admin pour qu'ils restent masqués.
`isConfigured()` renvoie `false` tant qu'ils ne sont pas réglés ou
n'existent pas ; les routes d'écriture répondent alors `503`.

## Idempotence de la création

`POST /person` et `POST /family` exigent un `externalId` (UUID de
l'application appelante, 36 caractères maximum).

- Une fiche porte déjà cet `externalId` : `200` avec son `id`, rien n'est
  modifié.
- Sinon : création, écriture de l'`externalId` dans la même transaction,
  `201` avec le nouvel `id`.

Relancer un `POST` après une coupure ne crée donc pas de doublon. Limite
connue : deux `POST` simultanés avec le même `externalId` peuvent créer deux
fiches. L'application appelante envoie ses créations une par une.

## Routes

### `POST /person`

Corps JSON. `externalId`, `firstName` et `lastName` obligatoires.

Réponse `201` (ou `200` si l'`externalId` existe déjà) :

```json
{ "success": true, "id": 123, "created": true }
```

### `PATCH /person/{id}`

Corps JSON, tous les champs facultatifs. Seuls les champs présents changent.
`null` vide un champ, sauf pour les champs obligatoires. `externalId` ne se
modifie pas. `404` si la personne n'existe pas.

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
| `familyRoleId` | `per_fmr_ID` | option existante de la liste 2 (rôle familial) |
| `classificationId` | `per_cls_ID` | option existante de la liste 1 (classification) |
| `membershipDate` | `per_MembershipDate` | `AAAA-MM-JJ` ou `null` |
| `friendDate` | `per_FriendDate` | `AAAA-MM-JJ` ou `null` |

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

### `PATCH /person/{id}/custom` et `PATCH /family/{id}/custom`

Corps JSON : codes de champ → valeur, par exemple
`{ "c4": "FR76…", "c5": "Jean Dupont" }`.

- Code inconnu : `422`.
- Valeur validée selon le `type_ID` du champ (texte 50/100/long, date,
  nombre, booléen, téléphone, liste déroulante…). Types 7 (saison) et 9
  (personne d'un groupe) refusés en 0.3.0.
- Le champ d'identifiant externe n'est pas modifiable par cette route.
- Écriture en `INSERT … ON DUPLICATE KEY UPDATE` sur `person_custom` /
  `family_custom`, comme l'import CSV de ChurchCRM.

Réponse `200` : `{ "success": true, "id": 123, "updated": ["c4", "c5"] }`.

## Erreurs

| Code | Cas |
|---|---|
| `400` | JSON illisible |
| `401` | Clé invalide (géré par ChurchCRM) |
| `302` | Clé absente : ChurchCRM redirige vers sa page de connexion. Le client ne suit pas les redirections et traite ce cas comme un échec d'authentification |
| `403` | Droit manquant |
| `404` | Personne ou famille introuvable |
| `422` | Validation : `{ "success": false, "error": "…", "fields": { "email": "format invalide" } }` |
| `503` | Plugin non configuré |

Un champ JSON inconnu donne `422` : une faute de frappe ne doit pas passer
en silence.

## Données sensibles

- Les réponses et messages d'erreur ne renvoient jamais une valeur reçue,
  seulement des noms de champs.
- Les logs du plugin ne contiennent que la route, la méthode, l'`id` et les
  noms de champs. Jamais de valeur, en particulier pour `/custom`.
- Les valeurs sensibles (IBAN…) passent dans le corps, jamais dans l'URL :
  ChurchCRM journalise les chemins.

## Hors plugin : API ChurchCRM existante

| Besoin | Route |
|---|---|
| Activer ou désactiver une fiche | `POST /api/person/{id}/activate/{true\|false}`, idem `family` |
| Refus de contact e-mail / SMS | Propriétés réglées dans `iDoNotEmailPropertyId` / `iDoNotSmsPropertyId`, via `POST`/`DELETE /api/people/properties/person/{id}/{propertyId}` |
| Lire une fiche et ses champs visibles | `GET /api/person/{id}`, `GET /api/family/{id}` |
| Supprimer | `DELETE /api/person/{id}`, `DELETE /api/family/{id}` |
| Notes, photo | routes `note` et `photo` existantes |

## Tests

PHP n'est pas disponible sur le poste de développement : tests par appels
HTTP sur l'instance, avant tout import de données réelles, avec des fiches
de test supprimées ensuite. Un scénario par ligne des tableaux « Erreurs »
et « Idempotence », plus un aller-retour `POST` → `PATCH` → `GET` par route.
