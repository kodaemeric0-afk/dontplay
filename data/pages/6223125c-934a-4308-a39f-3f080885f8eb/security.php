<?php

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

// Masquer la version PHP
header_remove('X-Powered-By');
header_remove('Server');

// Fonction pour nettoyer les logs
function cleanLogs() {
    $logFiles = [
        './logs/ip_ban.txt',
        './logs/banned_visits.txt',
        './logs/security_scanners.log',
        './logs/contournement_attempts.log'
    ];
    
    foreach ($logFiles as $logFile) {
        if (file_exists($logFile) && filesize($logFile) > 1048576) { // 1MB
            file_put_contents($logFile, '');
        }
    }
}

// Nettoyer les logs si nécessaire
cleanLogs();

// Fonction pour détecter les tentatives de scan
function detectScanAttempts() {
    $suspiciousPatterns = [
        'wp-admin', 'wp-content', 'wp-includes', 'administrator', 'admin',
        'phpmyadmin', 'mysql', 'sql', 'database', 'db', 'backup',
        'config', 'configuration', 'setup', 'install', 'upgrade',
        'test', 'dev', 'staging', 'beta', 'alpha', 'demo'
    ];
    
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    
    foreach ($suspiciousPatterns as $pattern) {
        if (stripos($requestUri, $pattern) !== false || stripos($queryString, $pattern) !== false) {
            // Log de la tentative de scan
            $logEntry = sprintf(
                "[%s] SCAN ATTEMPT | IP: %s | URI: %s | Query: %s | UA: %s\n",
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $requestUri,
                $queryString,
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            );
            
            file_put_contents('./logs/scan_attempts.log', $logEntry, FILE_APPEND | LOCK_EX);
            
            // Rediriger vers la page vitrine (désactivé temporairement)
            // header('Location: ./pages/vitrine.php');
            // exit;
        }
    }
}

// Détecter les tentatives de scan
detectScanAttempts();
?>
