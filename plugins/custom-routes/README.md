<div align="center">

# 🧩 Custom Routes

**Plugin ChurchCRM : les routes sur mesure que l'API ne fournit pas.**

Personnes, familles, champs personnalisés, événements et présences, sous
`/plugins/custom-routes/`, pour une application externe (intranet, outil de synchronisation).
On y ajoute une route quand un besoin apparaît.

![Version](https://img.shields.io/badge/version-0.8.0-blue) <!-- x-release-please-version -->
![ChurchCRM](https://img.shields.io/badge/ChurchCRM-%E2%89%A5%207.8.0-2a6db0)
![PHP](https://img.shields.io/badge/PHP-8.4-777bb4)
[![Release](https://img.shields.io/github/v/release/EBVN-Tech/churchcrm-plugin?filter=custom-routes-*)](https://github.com/EBVN-Tech/churchcrm-plugin/releases?q=custom-routes)

[Démarrage rapide](#-démarrage-rapide) · [Routes](#-routes) · [Réglages](#%EF%B8%8F-réglages) · [Mise à jour](#-mise-à-jour) · [Construire le zip](#-construire-le-zip) · [Spécification](docs/SPEC.md)

</div>

---

## Pourquoi

L'API de ChurchCRM 7.8.0 lit les fiches, mais ne sait ni les lister toutes, ni les créer,
ni les modifier depuis une application externe. Custom Routes ajoute ce qui manque, au fil des besoins :

| | |
|---|---|
| 🔁 **Création idempotente** | `POST` exige un `externalId` : relancer après une coupure ne crée pas de doublon. |
| ✏️ **Modification partielle** | `PATCH` n'écrit que les champs envoyés. Un champ inconnu donne `422`, jamais un oubli silencieux. |
| 🔐 **Droits de ChurchCRM** | Clé `x-api-key`, droits du compte de la clé, sécurité de chaque champ personnalisé. |
| 🙈 **Groupe exclu** | Les comptes techniques d'un groupe réglé ne sont jamais renvoyés ni modifiables. |
| 🧾 **Historique conservé** | Les écritures passent par les modèles Propel : notes d'historique et hooks de ChurchCRM inclus, jamais de SQL direct. |

## 🚀 Démarrage rapide

**1. Créer les champs d'identifiant externe.** Dans ChurchCRM, un champ personnalisé nommé
`ext-id` (« Text Field (50 char) », sécurité Admin) pour les personnes, et un pour les familles.

**2. Installer.** Admin → Plugins → installation par URL, avec :

- l'URL du zip de la [dernière release `custom-routes-v…`](https://github.com/EBVN-Tech/churchcrm-plugin/releases?q=custom-routes) ;
- son SHA-256, indiqué dans la release ;
- l'id `custom-routes`.

ChurchCRM marque le plugin « unverified » : c'est normal pour un plugin absent du registre officiel.

**3. Vérifier**, avec la clé API du compte de service :

```bash
curl -H "x-api-key: <clé>" https://<churchcrm>/plugins/custom-routes/ping
```

## 🧭 Routes

Base : `/plugins/custom-routes`. Détail des champs, réponses et erreurs : [`docs/SPEC.md`](docs/SPEC.md).

| Méthode | Route | Rôle | Droit exigé |
|---|---|---|---|
| `GET` | `/ping` | vérifie l'installation et les réglages | clé valide |
| `GET` | `/{persons,families}` | listes complètes, hors groupe exclu | clé valide |
| `POST` | `/{person,family}` | crée une fiche (idempotent par `externalId`) | AddRecords |
| `PATCH` | `/{person,family}/{id}` | modifie les champs envoyés | EditRecords |
| `GET` | `/{person,family}/{id}/custom` | lit les champs personnalisés autorisés | sécurité de chaque champ |
| `PATCH` | `/{person,family}/{id}/custom` | écrit des champs personnalisés | EditRecords + sécurité de chaque champ |
| `PATCH` | `/person/{id}/properties` | pose ou retire « Ne pas contacter » (e-mail, SMS) | EditRecords |
| `GET` | `/lists/{id}` | options d'une liste ChurchCRM (classification, rôle familial…) | clé valide |
| `POST` | `/events` | crée un événement | Add Event |
| `PATCH` | `/events/{id}` | modifie un événement | Add Event |
| `DELETE` | `/events/{id}` | supprime un événement sans présence | Add Event |
| `GET` | `/events/{id}/attendance` | présences d'un événement, hors groupe exclu | clé valide |

Lire, activer ou supprimer une fiche, les notes et la photo restent dans l'API ChurchCRM
existante (voir « Hors plugin » dans la [spécification](docs/SPEC.md#hors-plugin--api-churchcrm-existante)).

## ⚙️ Réglages

| Clé | Par défaut | Rôle |
|---|---|---|
| `personExternalIdField` | `ext-id` | champ personnalisé de personne qui reçoit l'identifiant externe |
| `familyExternalIdField` | `ext-id` | champ personnalisé de famille qui reçoit l'identifiant externe |
| `excludedGroupName` | vide | groupe dont les membres ne sont jamais renvoyés ni modifiables |

Un champ ou un groupe introuvable, ou en double, fait répondre `503` aux routes qui en
dépendent : pas d'écriture sans identifiant externe, pas de fiche qui aurait dû être masquée.

## 🔼 Mise à jour

L'installeur de ChurchCRM n'écrase jamais un plugin installé : désinstaller l'ancienne
version, puis installer la nouvelle par URL avec son SHA-256.

> ⚠️ La désinstallation efface les réglages du plugin : les ressaisir après chaque mise à jour.

**Depuis `people-sync` (0.7.1 et avant).** Le plugin s'appelait People Sync. Le nouvel id
fait de `custom-routes` un autre plugin pour ChurchCRM, et change l'URL de base des routes :

1. installer `custom-routes` et ressaisir ses réglages ;
2. remplacer `/plugins/people-sync` par `/plugins/custom-routes` dans l'application appelante ;
3. désinstaller `people-sync`.

## 📦 Construire le zip

Depuis la racine du dépôt :

```bash
scripts/build.sh custom-routes
```

Le script produit `dist/custom-routes-<version>.zip` (un seul dossier racine `custom-routes`,
aucun fichier caché) et affiche son SHA-256. Les releases sont automatiques : voir
[« Releases »](../../README.md#-releases) à la racine du dépôt.

Tests : `php -l` en PHP 8.4, puis des appels HTTP sur une instance de test (voir
« Tests » dans la [spécification](docs/SPEC.md#tests)).
