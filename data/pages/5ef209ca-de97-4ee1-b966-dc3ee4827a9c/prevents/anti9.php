<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab9_get_ip')) {
    function _ab9_get_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $p = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($p[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ── Rate limiting ────────────────────────────────────────────
if (!function_exists('_ab9_rate_check')) {
    function _ab9_rate_check($ip, $file, $maxReq = 80, $window = 60) {
        $data = [];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $data = @json_decode($raw, true) ?: [];
        }
        $now = time();
        if (!isset($data[$ip]) || !is_array($data[$ip])) $data[$ip] = [];

        // Remove timestamps outside window
        $data[$ip] = array_values(array_filter($data[$ip], function ($ts) use ($now, $window) {
            return ($now - $ts) < $window;
        }));
        $data[$ip][] = $now;

        // Prune empty IPs
        foreach (array_keys($data) as $k) {
            if (empty($data[$k])) unset($data[$k]);
        }

        @file_put_contents($file, json_encode($data), LOCK_EX);
        return count($data[$ip]) > $maxReq;
    }
}

// ── Tor exit node check ──────────────────────────────────────
if (!function_exists('_ab9_is_tor')) {
    function _ab9_is_tor($ip, $cacheFile) {
        $maxAge = 86400; // refresh daily

        if (!file_exists($cacheFile) || (time() - filemtime($cacheFile)) > $maxAge) {
            $ctx  = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'Mozilla/5.0']]);
            $list = @file_get_contents('https://check.torproject.org/torbulkexitlist', false, $ctx);
            if ($list !== false && strlen($list) > 100) {
                @file_put_contents($cacheFile, $list, LOCK_EX);
            }
        }

        if (!file_exists($cacheFile)) return false;

        $lines = @file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return false;

        return in_array(trim($ip), array_map('trim', $lines));
    }
}

$_ab9_ip = _ab9_get_ip();

// Tor check
if (_ab9_is_tor($_ab9_ip, __DIR__ . '/tor_exits.txt')) {
    $_SESSION['bot']        = true;
    $_SESSION['bot_reason'] = 'tor_exit_node';
    return;
}

// Rate limit: max 80 req/min
if (_ab9_rate_check($_ab9_ip, __DIR__ . '/rate_limit.json', 80, 60)) {
    $_SESSION['bot']        = true;
    $_SESSION['bot_reason'] = 'rate_limit_exceeded';
    return;
}
