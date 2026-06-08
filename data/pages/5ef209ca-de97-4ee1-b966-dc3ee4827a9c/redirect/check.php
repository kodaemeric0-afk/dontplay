<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

function getLocalClientIps() {
    $ips = [];

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $ips[] = $_SERVER['REMOTE_ADDR'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($forwardedIps as $ip) {
            $ip = trim($ip);
            if ($ip && !in_array($ip, $ips)) {
                $ips[] = $ip;
            }
        }
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP']) && !in_array($_SERVER['HTTP_CLIENT_IP'], $ips)) {
        $ips[] = $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && !in_array($_SERVER['HTTP_CF_CONNECTING_IP'], $ips)) {
        $ips[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP']) && !in_array($_SERVER['HTTP_X_REAL_IP'], $ips)) {
        $ips[] = $_SERVER['HTTP_X_REAL_IP'];
    }

    $ips = array_filter($ips, function($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP);
    });
    $ips = array_unique($ips);

    return $ips;
}

if (isset($_GET['check']) && $_GET['check'] === '1') {
    set_time_limit(2);

    $redirectsFile = '../redirect/redirects.txt';
    $clientIps = getLocalClientIps();

    if (file_exists($redirectsFile)) {
        try {
            $lines = file($redirectsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = preg_split('/\s*-\s*/', $line);
                if (count($parts) === 3) {
                    foreach ($clientIps as $ip) {
                        if ($parts[0] === $ip) {
                            echo $line;
                            exit;
                        }
                    }
                }
                if ((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) > 1.8) {
                    break;
                }
            }
        } catch (Exception $e) {
        }
    }
    echo '';
    exit;
}