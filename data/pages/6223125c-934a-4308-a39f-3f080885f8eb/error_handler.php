<?php
// Gestionnaire d'erreurs pour masquer les erreurs et éviter la détection

// Désactiver l'affichage des erreurs
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', './logs/php_errors.log');

// Fonction de gestion des erreurs personnalisée
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // Log l'erreur dans un fichier sécurisé
    $errorMsg = date('Y-m-d H:i:s') . " - Error [$errno]: $errstr in $errfile on line $errline\n";
    file_put_contents('./logs/php_errors.log', $errorMsg, FILE_APPEND | LOCK_EX);
    
    // Rediriger vers la page vitrine en cas d'erreur critique
    if ($errno === E_ERROR || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
        header('Location: ./pages/vitrine.php');
        exit;
    }
    
    return true; // Empêcher l'affichage de l'erreur
}

// Fonction de gestion des exceptions
function customExceptionHandler($exception) {
    // Log l'exception
    $errorMsg = date('Y-m-d H:i:s') . " - Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\n";
    file_put_contents('./logs/php_errors.log', $errorMsg, FILE_APPEND | LOCK_EX);
    
    // Rediriger vers la page vitrine
    header('Location: ./pages/vitrine.php');
    exit;
}

// Enregistrer les gestionnaires d'erreurs
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');

// Masquer les informations de version
header_remove('X-Powered-By');
header_remove('Server');

// Headers de sécurité supplémentaires
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

// Fonction pour nettoyer les logs d'erreurs
function cleanErrorLogs() {
    $errorLog = './logs/php_errors.log';
    if (file_exists($errorLog) && filesize($errorLog) > 5242880) { // 5MB
        file_put_contents($errorLog, '');
    }
}

// Nettoyer les logs si nécessaire
cleanErrorLogs();
?>
