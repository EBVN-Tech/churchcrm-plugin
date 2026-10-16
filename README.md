# People Sync — plugin ChurchCRM

Plugin communautaire pour ChurchCRM 7.8.0 ou plus. Il expose sous
`/plugins/people-sync/` les routes d'écriture des personnes et familles que
l'API ChurchCRM ne fournit pas, pour une application externe (intranet,
outil de synchronisation). Les routes sont authentifiées par l'en-tête `x-api-key`.

Version 0.6.1 (ajoute `GET /events/{id}/attendance` ; la 0.5.0 avait ajouté `PATCH /person/{id}/properties` et `GET /lists/{id}`). Spécification complète : [`docs/SPEC.md`](docs/SPEC.md).

| Méthode | Route | Rôle |
|---|---|---|
| `GET` | `/plugins/people-sync/ping` | vérifie l'installation et les réglages |
| `GET` | `/plugins/people-sync/{persons,families}` | listes complètes, hors groupe exclu |
| `POST` | `/plugins/people-sync/{person,family}` | crée une fiche (idempotent par `externalId`) |
| `PATCH` | `/plugins/people-sync/{person,family}/{id}` | modifie les champs envoyés |
| `GET` | `/plugins/people-sync/{person,family}/{id}/custom` | lit les champs personnalisés autorisés |
| `PATCH` | `/plugins/people-sync/{person,family}/{id}/custom` | écrit des champs personnalisés |

Prérequis : un champ personnalisé nommé `ext-id` (texte 50, sécurité Admin)
pour les personnes et un pour les familles.

## Installation

Admin → Plugins → installation par URL, avec l'URL du zip de la release,
son SHA-256 (indiqué dans la release) et l'id `people-sync`. Pour changer
de version : désinstaller l'ancienne, puis installer la nouvelle. ChurchCRM marque
le plugin « unverified » : c'est normal pour un plugin absent du registre officiel.

## Construire le zip

Le zip doit contenir un seul dossier racine nommé comme l'id du plugin, et
aucun fichier caché :

```bash
mkdir -p dist/people-sync
cp -R plugin.json routes src dist/people-sync/
(cd dist && zip -X -r -q people-sync-<version>.zip people-sync)
shasum -a 256 dist/people-sync-<version>.zip
```
