<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab8_get_ip')) {
    function _ab8_get_ip() {
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

if (!function_exists('_ab8_fetch_isp')) {
    function _ab8_fetch_isp($ip, $timeoutSeconds = 3) {
        $url = 'http://ipinfo.io/' . rawurlencode($ip) . '/org';

        $context = stream_context_create([
            'http' => [
                'timeout'        => $timeoutSeconds,
                'ignore_errors'  => true,
                'user_agent'     => 'Mozilla/5.0',
                'method'         => 'GET',
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) return null;
        return trim($result);
    }
}

if (!function_exists('_ab8_isp_is_blocked')) {
    function _ab8_isp_is_blocked($isp, $blockedIsps) {
        if ($isp === null || $isp === '') return false;
        $ispLower = strtolower($isp);
        foreach ($blockedIsps as $blocked) {
            if (stripos($ispLower, strtolower($blocked)) !== false) {
                return $blocked;
            }
        }
        return false;
    }
}

$_ab8_ip = _ab8_get_ip();

$_ab8_blockedIsps = [
    'DigitalOcean', 'Amazon', 'Google', 'Microsoft', 'Linode', 'Hetzner',
    'OVH', 'Vultr', 'Scaleway', 'LeaseWeb', 'SoftLayer', 'Rackspace',
    'Choopa', 'Constant', 'Zenlayer', 'Psychz', 'Path.net', 'QuadraNet',
    'ServerMania', 'SingleHop', 'ColoCrossing', 'Netsolus', 'Sharktech',
    'M247', 'Colocrossing',
];

// Use cached ISP info from firewall.php if available
// firewall.php stores 'as' (ASN string) and 'isp' (ISP name)
$_ab8_isp = null;
if (!empty($_SESSION['ip_info'])) {
    $asParts  = isset($_SESSION['ip_info']['as'])  ? $_SESSION['ip_info']['as']  : '';
    $ispParts = isset($_SESSION['ip_info']['isp']) ? $_SESSION['ip_info']['isp'] : '';
    $_ab8_isp = $asParts . ' ' . $ispParts;
} else {
    // Only fetch if IP looks valid and not private
    $isPrivate = (
        strpos($_ab8_ip, '127.') === 0 ||
        strpos($_ab8_ip, '10.') === 0 ||
        strpos($_ab8_ip, '192.168.') === 0 ||
        strpos($_ab8_ip, '172.') === 0 ||
        $_ab8_ip === '::1'
    );

    if (!$isPrivate) {
        $_ab8_isp = _ab8_fetch_isp($_ab8_ip);
    }
}

if ($_ab8_isp !== null) {
    $_ab8_match = _ab8_isp_is_blocked($_ab8_isp, $_ab8_blockedIsps);
    if ($_ab8_match !== false) {
        $_SESSION['bot'] = true;
        $_SESSION['bot_reason'] = 'blocked_isp:' . $_ab8_match;
        return;
    }
}
