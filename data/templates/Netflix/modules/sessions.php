<?php
// Protection contre l'accès direct
if (php_sapi_name() !== 'cli' && !defined('ALLOW_INCLUDE')) {
    http_response_code(404);
    exit('Not Found');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    if (!isset($_COOKIE['uid'])) {
        $uid = uniqid('user_', true);

        // Cookie max 1 jour
        setcookie(
            'uid',
            $uid,
            [
                'expires'  => time() + 86400,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_SESSION['user_id'] = $uid;
    } else {
        $_SESSION['user_id'] = $_COOKIE['uid'];
    }
}

$userId = $_SESSION['user_id'];
