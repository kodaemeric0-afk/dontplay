<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab7_get_ip')) {
    function _ab7_get_ip() {
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

if (!function_exists('_ab7_pattern_is_regex')) {
    function _ab7_pattern_is_regex($pattern) {
        // Consider it a regex if it contains common regex special characters
        return (bool) preg_match('/[\\^$.*+?()\\[\\]{}|\\\\]/', $pattern);
    }
}

if (!function_exists('_ab7_ua_matches_pattern')) {
    function _ab7_ua_matches_pattern($ua, $pattern) {
        if (_ab7_pattern_is_regex($pattern)) {
            return (bool) @preg_match('/' . $pattern . '/i', $ua);
        }
        return stripos($ua, $pattern) !== false;
    }
}

$_ab7_ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

// Default fallback bot list
$_ab7_botPatterns = [
    'googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot',
    'applebot', 'twitterbot', 'facebookexternalhit', 'linkedinbot',
    'whatsapp', 'telegrambot',
];

// Load bot patterns from JSON file if it exists
$_ab7_jsonFile = __DIR__ . '/list_bots.json';
if (file_exists($_ab7_jsonFile)) {
    $raw = @file_get_contents($_ab7_jsonFile);
    if ($raw !== false) {
        $decoded = @json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded)) {
            $_ab7_botPatterns = $decoded;
        }
    }
}

foreach ($_ab7_botPatterns as $pattern) {
    if (_ab7_ua_matches_pattern($_ab7_ua, $pattern)) {
        $_SESSION['bot'] = true;
        $_SESSION['bot_reason'] = 'bot_list_match:' . $pattern;
        return;
    }
}
