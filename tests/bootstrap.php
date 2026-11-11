<?php

/*
 * Die Integrationstests brauchen eine gestartete REDAXO-Installation mit Datenbank und ffmpeg – Pfad zu deren
 * Bootstrap in REDAXO_BOOTSTRAP, im Entwicklungs-Repository des Cores zum Beispiel:
 *
 *     REDAXO_BOOTSTRAP=/pfad/zu/redaxo/.tools/bootstrap.php vendor/bin/phpunit -c /pfad/zu/video_editor/phpunit.dist.xml
 *
 * Ohne REDAXO_BOOTSTRAP werden sie übersprungen.
 */

$bootstrap = getenv('REDAXO_BOOTSTRAP');
if (is_string($bootstrap) && '' !== $bootstrap) {
    require $bootstrap;
    define('VIDEO_EDITOR_INTEGRATION', true);
} else {
    require dirname(__DIR__) . '/vendor/autoload.php';
    define('VIDEO_EDITOR_INTEGRATION', false);
}

// Testklassen des AddOns (autoload-dev greift nicht, wenn ein anderes Projekt den Autoloader stellt)
spl_autoload_register(static function (string $class): void {
    $prefix = 'FriendsOfRedaxo\\VideoEditor\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
