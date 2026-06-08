<?php
include('../common/sub_includes.php');
include('../firewall.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $country = isset($_POST['country']) ? trim($_POST['country']) : '';
    $os      = isset($_POST['os']) ? trim($_POST['os']) : '';
    $ip      = isset($_POST['ip']) ? trim($_POST['ip']) : '';
    $isp     = isset($_POST['isp']) ? trim($_POST['isp']) : '';
    $date    = isset($_POST['date']) ? trim($_POST['date']) : '';

    if ($country !== '' && $os !== '' && $ip !== '' && $date !== '') {
        $line = "$country | $os | $ip | $isp | $date" . PHP_EOL;
        $file = dirname(__DIR__) . '/data/click.txt';
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        http_response_code(200);
        echo "OK";
    } else {
        http_response_code(400);
        echo "Missing data";
    }
} else {
    http_response_code(405);
    echo "Method Not Allowed";
}
