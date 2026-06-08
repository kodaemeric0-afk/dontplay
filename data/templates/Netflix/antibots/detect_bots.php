<?php
declare(strict_types=1);

// 🔧 Configuration des chemins
define('BOT_JSON_FILE', __DIR__ . '/list_bots.json');
define('LOG_FILE', __DIR__ . '/bot-blocked-' . date('Y-m-d') . '.log'); // rotation journalière
define('WHITELIST_FILE', __DIR__ . '/../config/whitelist.txt'); // ⚠️ adapte le chemin si besoin

/* -------------------------------------------------------------------------- */
/*                         🔹 Récupération IP réelle                          */
/* -------------------------------------------------------------------------- */
if (!function_exists('getUserIP')) {
    function getUserIP(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ipList = explode(',', (string)$_SERVER[$key]);
                return trim(reset($ipList));
            }
        }
        return 'UNKNOWN';
    }
}

/* -------------------------------------------------------------------------- */
/*                    🔹 Détection des scanners de sécurité                   */
/* -------------------------------------------------------------------------- */
if (!function_exists('isSecurityScanner')) {
    function isSecurityScanner(): bool {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Charger les patterns depuis le fichier JSON
        $scannersFile = __DIR__ . '/security_scanners.json';
        if (!file_exists($scannersFile)) {
            return false;
        }
        
        $scannersData = json_decode(file_get_contents($scannersFile), true);
        if (!isset($scannersData['scanners']) || !is_array($scannersData['scanners'])) {
            return false;
        }
        
        // Parcourir tous les scanners et leurs patterns
        foreach ($scannersData['scanners'] as $scanner) {
            if (isset($scanner['patterns']) && is_array($scanner['patterns'])) {
                foreach ($scanner['patterns'] as $pattern) {
                    // Les patterns contiennent déjà les délimiteurs et modificateurs
                    if (preg_match($pattern, $userAgent)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
}

/* -------------------------------------------------------------------------- */
/*                         🔹 Blocage + Logging                               */
/* -------------------------------------------------------------------------- */
function blockAccess(string $reason, string $ip, string $ua): void {
    $log = sprintf("[%s] IP: %s | Reason: %s | UA: %s\n", date('Y-m-d H:i:s'), $ip, $reason, $ua);
    file_put_contents(LOG_FILE, $log, FILE_APPEND | LOCK_EX);

    header('HTTP/1.1 403 Forbidden');
    exit("403 - Bot détecté");
}

/* -------------------------------------------------------------------------- */
/*                         🔹 Vérification IP                                 */
/* -------------------------------------------------------------------------- */
$ip = getUserIP();
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    blockAccess("IP invalide", $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');
}

/* -------------------------------------------------------------------------- */
/*              🔹 Vérification des scanners de sécurité                      */
/* -------------------------------------------------------------------------- */
// Rediriger les scanners de sécurité vers la page vitrine pour éviter le report
if (isSecurityScanner()) {
    // Log de la visite du scanner (optionnel)
    $logFile = __DIR__ . '/../logs/security_scanners.log';
    $timestamp = date('Y-m-d H:i:s');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    file_put_contents(
        $logFile,
        "[$timestamp] IP: $ip | Scanner UA: $userAgent" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    
    // Redirection vers la page vitrine avec headers de sécurité
    header("HTTP/1.1 200 OK");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: ../pages/vitrine.php");
    exit;
}

/* -------------------------------------------------------------------------- */
/*                         🔹 Vérification whitelist                          */
/* -------------------------------------------------------------------------- */
$whitelist = file_exists(WHITELIST_FILE)
    ? array_map('trim', file(WHITELIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    : [];

if (in_array($ip, $whitelist, true)) {
    return; // ✅ Sortie immédiate pour IP whitelistée
}

/* -------------------------------------------------------------------------- */
/*                         🔹 Vérification User-Agent                         */
/* -------------------------------------------------------------------------- */
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (empty($userAgent)) {
    blockAccess("User-Agent vide", $ip, 'empty');
}

/* -------------------------------------------------------------------------- */
/*                         🔹 Chargement liste JSON                           */
/* -------------------------------------------------------------------------- */
if (!file_exists(BOT_JSON_FILE)) {
    error_log("❌ Fichier bot JSON introuvable.");
    return;
}

$botsList = json_decode(file_get_contents(BOT_JSON_FILE), true);
if (!is_array($botsList)) {
    error_log("❌ Erreur de parsing JSON dans BOT_JSON_FILE.");
    return;
}

/* -------------------------------------------------------------------------- */
/*                         🔹 Détection via motifs                            */
/* -------------------------------------------------------------------------- */
foreach ($botsList as $bot) {
    if (empty($bot) || trim($bot) === '') continue;

    // Vérifier si c'est un pattern regex (contient des caractères spéciaux)
    $isRegex = preg_match('/[\[\]{}()*+?^$|\\\\]/', $bot);
    
    if ($isRegex) {
        // C'est un pattern regex, l'utiliser directement
        if (preg_match('/' . $bot . '/i', $userAgent)) {
            blockAccess("Détecté par pattern regex : $bot", $ip, $userAgent);
        }
    } else {
        // C'est un pattern simple, recherche insensible à la casse
        if (stripos($userAgent, $bot) !== false) {
            blockAccess("Détecté par pattern simple : $bot", $ip, $userAgent);
        }
    }
}
