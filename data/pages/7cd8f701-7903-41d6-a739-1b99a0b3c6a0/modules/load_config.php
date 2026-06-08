<?php
/**
 * Module de chargement de configuration
 * Remplace le système .env par le fichier config.php
 */

// Protection contre l'accès direct
if (php_sapi_name() !== 'cli' && !defined('ALLOW_INCLUDE')) {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Charge la configuration depuis config.php
 */
function loadConfig(): array {
    $configFile = __DIR__ . '/../config.php';

    if (!file_exists($configFile)) {
        throw new Exception("Fichier de configuration introuvable : $configFile");
    }

    if (!defined('ALLOW_INCLUDE')) {
        define('ALLOW_INCLUDE', true);
    }

    $config = include $configFile;

    if (!is_array($config)) {
        throw new Exception("Le fichier de configuration doit retourner un tableau");
    }

    return $config;
}

/**
 * Récupère une valeur de configuration
 */
function getConfig(string $key, $default = null) {
    static $config = null;

    if ($config === null) {
        try {
            $config = loadConfig();
        } catch (Exception $e) {
            die("⛔ Erreur lors du chargement de la configuration : " . $e->getMessage());
        }
    }

    return $config[$key] ?? $default;
}

// Chargement automatique de la configuration
try {
    $GLOBALS['app_config'] = loadConfig();

    // Remplir $_ENV pour la compatibilité avec l'ancien code
    foreach ($GLOBALS['app_config'] as $key => $value) {
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
} catch (Exception $e) {
    die("⛔ Erreur lors du chargement de la configuration : " . $e->getMessage());
}
