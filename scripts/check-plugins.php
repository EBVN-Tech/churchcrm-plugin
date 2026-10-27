<?php

// Checks each plugin's plugin.json: php scripts/check-plugins.php
// Applies ChurchCRM 7.8.0's rules (PluginMetadata::isValid, PluginManager's
// PSR-4 autoloader) and the repository's (id = folder, releases).

$root = dirname(__DIR__);
$errors = [];

$readJson = function (string $path) use (&$errors): ?array {
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        $errors[] = "$path: invalid or unreadable JSON";
        return null;
    }
    return $data;
};

$config = $readJson("$root/release-please-config.json") ?? [];
$manifest = $readJson("$root/.release-please-manifest.json") ?? [];

$dirs = glob("$root/plugins/*", GLOB_ONLYDIR) ?: [];
if ($dirs === []) {
    $errors[] = 'no plugin in plugins/';
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
            $errors[] = "$file: \"$key\" missing or empty";
        }
    }
    if (($plugin['id'] ?? null) !== $id) {
        $errors[] = "$file: id must be \"$id\", the folder name";
    }
    if (isset($plugin['version']) && !preg_match('/^\d+\.\d+\.\d+$/', (string) $plugin['version'])) {
        $errors[] = "$file: version \"{$plugin['version']}\" is not X.Y.Z";
    }

    // ChurchCRM loads src/<Class>.php for mainClass's namespace.
    $mainClass = (string) ($plugin['mainClass'] ?? '');
    $separator = strrpos($mainClass, '\\');
    if ($separator === false) {
        $errors[] = "$file: mainClass \"$mainClass\" has no namespace";
    } else {
        $namespace = substr($mainClass, 0, $separator);
        $class = substr($mainClass, $separator + 1);
        $source = (string) @file_get_contents("$dir/src/$class.php");
        if ($source === '') {
            $errors[] = "$file: src/$class.php not found for mainClass";
        } elseif (!preg_match('/^namespace\s+' . preg_quote($namespace, '/') . '\s*;/m', $source)
            || !preg_match('/\bclass\s+' . preg_quote($class, '/') . '\b/', $source)) {
            $errors[] = "$file: src/$class.php does not declare $mainClass";
        }
    }

    if (!empty($plugin['routesFile']) && !is_file("$dir/{$plugin['routesFile']}")) {
        $errors[] = "$file: routesFile \"{$plugin['routesFile']}\" not found";
    }

    $keys = [];
    foreach ($plugin['settings'] ?? [] as $i => $setting) {
        foreach (['key', 'label', 'type'] as $key) {
            if (empty($setting[$key])) {
                $errors[] = "$file: settings[$i] has no \"$key\"";
            }
        }
        $key = $setting['key'] ?? null;
        if ($key !== null && isset($keys[$key])) {
            $errors[] = "$file: duplicate setting \"$key\"";
        }
        $keys[$key] = true;
    }

    // Without these entries, release-please ignores the plugin.
    $path = "plugins/$id";
    if (!isset($config['packages'][$path])) {
        $errors[] = "release-please-config.json: \"$path\" missing from packages";
    } elseif (($config['packages'][$path]['component'] ?? null) !== $id) {
        $errors[] = "release-please-config.json: component of \"$path\" differs from \"$id\"";
    }
    if (!isset($manifest[$path])) {
        $errors[] = ".release-please-manifest.json: \"$path\" missing";
    } elseif ($manifest[$path] !== ($plugin['version'] ?? null)) {
        $errors[] = ".release-please-manifest.json: \"$path\" at {$manifest[$path]}, plugin.json at {$plugin['version']}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo count($dirs) . " valid plugin(s)\n";
