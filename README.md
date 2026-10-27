<div align="center">

# 🧩 Plugins ChurchCRM

**Les plugins ChurchCRM d'EBVN, un dossier par plugin.**

![ChurchCRM](https://img.shields.io/badge/ChurchCRM-%E2%89%A5%207.8.0-2a6db0)
![PHP](https://img.shields.io/badge/PHP-8.4-777bb4)
[![CI](https://github.com/EBVN-Tech/churchcrm-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/EBVN-Tech/churchcrm-plugin/actions/workflows/ci.yml)

</div>

---

## Plugins

| Plugin | Id | Rôle |
|---|---|---|
| [Custom Routes](plugins/custom-routes/README.md) | `custom-routes` | routes sur mesure que l'API ChurchCRM ne fournit pas, pour une application externe |

Chaque plugin s'installe séparément : Admin → Plugins → installation par URL, avec l'URL
du zip de sa release et son SHA-256. Voir le README du plugin.

## Organisation

```
plugins/<id>/        un plugin : plugin.json, routes/, src/, README.md, docs/
scripts/build.sh     construit le zip d'un plugin
scripts/check-plugins.php   vérifie le plugin.json de chaque plugin
phpstan.neon.dist    analyse statique des plugins
```

Chaque plugin a sa version, dans son `plugin.json`, et ses propres releases.

## ✍️ Commits

Les messages suivent [Conventional Commits](https://www.conventionalcommits.org/fr/v1.0.0/) :
la version de chaque plugin en dépend. Type en anglais, description en français.

| Type | Effet sur la version | Exemple |
|---|---|---|
| `fix:` | patch (0.8.0 → 0.8.1) | `fix: refuse le HTML dans le titre d'un événement` |
| `feat:` | mineure (0.8.0 → 0.9.0) | `feat: route de suppression d'un événement` |
| `feat!:` ou pied `BREAKING CHANGE:` | majeure (0.x : mineure) | `feat!: renomme l'id du plugin` |
| `docs:`, `chore:`, `ci:`, `refactor:`, `test:` | aucune | `docs: précise les erreurs 503` |

Une portée optionnelle nomme le plugin : `fix(custom-routes): …`. La version suit de toute
façon les fichiers touchés, pas la portée.

Seul « Squash and merge » est permis : le titre de la PR devient le message du commit sur
`main`. Le workflow « Titre de PR » refuse un titre hors format.

## 🚀 Releases

[release-please](https://github.com/googleapis/release-please) les gère, plugin par plugin
(`release-please-config.json`, `.release-please-manifest.json`) :

1. À chaque push sur `main`, il ouvre ou met à jour une PR de release par plugin modifié par
   un `fix:` ou un `feat:` : version montée dans `plugin.json` et le README, `CHANGELOG.md`.
2. Fusionner cette PR crée le tag `<id>-v<version>` et la release.
3. Le workflow construit alors le zip, l'attache à la release et écrit son SHA-256 dans les notes.

Un plugin sans changement n'a pas de release. Ajouter un plugin : une entrée dans
`packages` de `release-please-config.json`, et sa version actuelle dans le manifest
(`scripts/check-plugins.php` refuse un plugin oublié).

## 📦 Construire un zip

```bash
scripts/build.sh <id>
```

Le script produit `dist/<id>-<version>.zip`, avec un seul dossier racine `<id>` et sans
fichier caché, README, CHANGELOG ni docs, puis affiche son SHA-256.

## Tests

La CI vérifie, pour chaque plugin :

- la syntaxe PHP 8.4 (`php -l`) ;
- le `plugin.json` (`scripts/check-plugins.php`) : champs exigés par ChurchCRM, id égal au
  nom du dossier, `mainClass` et `routesFile` présents, entrées release-please ;
- le code avec PHPStan (niveau 6), contre le code de ChurchCRM 7.8.0 : classe inconnue,
  import manquant, mauvais appel ;
- la construction du zip.

En local, avec le zip de ChurchCRM décompressé dans `<dossier>` :

```bash
php scripts/check-plugins.php
CHURCHCRM_DIR=<dossier>/churchcrm phpstan analyse
```

Les tests de comportement se font par appels HTTP sur une instance de test : voir la
spécification de chaque plugin.
