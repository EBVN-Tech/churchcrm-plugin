# EBVN People Sync — plugin ChurchCRM

Plugin communautaire privé pour ChurchCRM 7.8.0 ou plus. Il expose sous
`/plugins/ebvn-sync/` les routes d'écriture des personnes et familles que
l'API ChurchCRM ne fournit pas, pour l'intranet de l'Église Baptiste Vie
Nouvelle. Les routes sont authentifiées par l'en-tête `x-api-key`.

Version 0.1.0 : routes de test seulement, aucune écriture en base.

| Méthode | Route | Rôle exigé | Réponse |
|---|---|---|---|
| `GET` | `/plugins/ebvn-sync/ping` | aucun (clé valide) | utilisateur authentifié et droit EditRecords |
| `POST` | `/plugins/ebvn-sync/echo` | EditRecords | noms des clés JSON reçues, jamais les valeurs |

## Installation

Admin → Plugins → installation par URL, avec l'URL du zip de la release,
son SHA-256 (indiqué dans la release) et l'id `ebvn-sync`. ChurchCRM marque
le plugin « unverified » : c'est normal pour un plugin privé.

## Construire le zip

Le zip doit contenir un seul dossier racine nommé comme l'id du plugin, et
aucun fichier caché :

```bash
mkdir -p dist/ebvn-sync
cp -R plugin.json routes src dist/ebvn-sync/
(cd dist && zip -X -r -q ebvn-sync-<version>.zip ebvn-sync)
shasum -a 256 dist/ebvn-sync-<version>.zip
```
