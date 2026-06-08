<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab4_get_ip')) {
    function _ab4_get_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// Block if User-Agent is empty (fast check)
$_ab4_ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim($_SERVER['HTTP_USER_AGENT']) : '';
if ($_ab4_ua === '') {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'missing_user_agent';
    return;
}

// Block if HTTP_ACCEPT is missing or empty
$_ab4_accept = isset($_SERVER['HTTP_ACCEPT']) ? trim($_SERVER['HTTP_ACCEPT']) : '';
if ($_ab4_accept === '') {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'missing_accept_header';
    return;
}

// Block if HTTP_ACCEPT_LANGUAGE is missing or empty
$_ab4_acceptLang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? trim($_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
if ($_ab4_acceptLang === '') {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'missing_accept_language_header';
    return;
}

// Block if HTTP_ACCEPT doesn't contain a browser-like content type
$_ab4_acceptLower = strtolower($_ab4_accept);
$_ab4_hasHtmlType = (
    strpos($_ab4_acceptLower, 'text/html') !== false ||
    strpos($_ab4_acceptLower, 'application/xhtml') !== false ||
    strpos($_ab4_acceptLower, 'application/xml') !== false ||
    strpos($_ab4_acceptLower, '*/*') !== false
);

if (!$_ab4_hasHtmlType) {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'invalid_accept_header';
    return;
}
