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
```

Chaque plugin a sa version, dans son `plugin.json`. Une release porte le tag
`<id>-v<version>`, par exemple `custom-routes-v0.8.1`.

## 📦 Construire un zip

```bash
scripts/build.sh <id>
```

Le script produit `dist/<id>-<version>.zip`, avec un seul dossier racine `<id>` et sans
fichier caché, README ni docs, puis affiche son SHA-256.

## Tests

La CI vérifie la syntaxe de chaque fichier PHP en 8.4 (`php -l`) et construit le zip de
chaque plugin. Les tests de comportement se font par appels HTTP sur une instance de test :
voir la spécification de chaque plugin.
