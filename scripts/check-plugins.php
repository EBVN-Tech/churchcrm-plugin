<?php

// Vérifie le plugin.json de chaque plugin : php scripts/check-plugins.php
// Reprend les règles de ChurchCRM 7.8.0 (PluginMetadata::isValid, autoloader
// PSR-4 de PluginManager) et celles du dépôt (id = dossier, releases).

$root = dirname(__DIR__);
$errors = [];

$readJson = function (string $path) use (&$errors): ?array {
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        $errors[] = "$path : JSON invalide ou illisible";
        return null;
    }
    return $data;
};

$config = $readJson("$root/release-please-config.json") ?? [];
$manifest = $readJson("$root/.release-please-manifest.json") ?? [];

$dirs = glob("$root/plugins/*", GLOB_ONLYDIR) ?: [];
if ($dirs === []) {
    $errors[] = 'aucun plugin dans plugins/';
}

foreach ($dirs as $dir) {
    $id = basename($dir);
    $file = "plugins/$id/plugin.json";
    $plugin = $readJson("$root/$file");
    if ($plugin === null) {
        continue;
    }

    foreach (['id', 'name', 'version', 'mainClass', 'minimumCRMVersion'] as $key) {
        if (empty($plugin[$key]) || !is_string($plugin[$key])) {
            $errors[] = "$file : « $key » manquant ou vide";
        }
    }
    if (($plugin['id'] ?? null) !== $id) {
        $errors[] = "$file : l'id doit être « $id », le nom du dossier";
    }
    if (isset($plugin['version']) && !preg_match('/^\d+\.\d+\.\d+$/', (string) $plugin['version'])) {
        $errors[] = "$file : version « {$plugin['version']} » hors format X.Y.Z";
    }

    // ChurchCRM charge src/<Classe>.php pour le namespace de mainClass.
    $mainClass = (string) ($plugin['mainClass'] ?? '');
    $separator = strrpos($mainClass, '\\');
    if ($separator === false) {
        $errors[] = "$file : mainClass « $mainClass » sans namespace";
    } else {
        $namespace = substr($mainClass, 0, $separator);
        $class = substr($mainClass, $separator + 1);
        $source = (string) @file_get_contents("$dir/src/$class.php");
        if ($source === '') {
            $errors[] = "$file : src/$class.php introuvable pour mainClass";
        } elseif (!preg_match('/^namespace\s+' . preg_quote($namespace, '/') . '\s*;/m', $source)
            || !preg_match('/\bclass\s+' . preg_quote($class, '/') . '\b/', $source)) {
            $errors[] = "$file : src/$class.php ne déclare pas $mainClass";
        }
    }

    if (!empty($plugin['routesFile']) && !is_file("$dir/{$plugin['routesFile']}")) {
        $errors[] = "$file : routesFile « {$plugin['routesFile']} » introuvable";
    }

    $keys = [];
    foreach ($plugin['settings'] ?? [] as $i => $setting) {
        foreach (['key', 'label', 'type'] as $key) {
            if (empty($setting[$key])) {
                $errors[] = "$file : settings[$i] sans « $key »";
            }
        }
        $key = $setting['key'] ?? null;
        if ($key !== null && isset($keys[$key])) {
            $errors[] = "$file : réglage « $key » en double";
        }
        $keys[$key] = true;
    }

    // Sans ces entrées, release-please ignore le plugin.
    $path = "plugins/$id";
    if (!isset($config['packages'][$path])) {
        $errors[] = "release-please-config.json : « $path » absent de packages";
    } elseif (($config['packages'][$path]['component'] ?? null) !== $id) {
        $errors[] = "release-please-config.json : component de « $path » différent de « $id »";
    }
    if (!isset($manifest[$path])) {
        $errors[] = ".release-please-manifest.json : « $path » absent";
    } elseif ($manifest[$path] !== ($plugin['version'] ?? null)) {
        $errors[] = ".release-please-manifest.json : « $path » en {$manifest[$path]}, plugin.json en {$plugin['version']}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo count($dirs) . " plugin(s) valide(s)\n";
