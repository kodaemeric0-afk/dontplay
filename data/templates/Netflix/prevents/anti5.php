<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab5_get_ip')) {
    function _ab5_get_ip() {
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

if (!function_exists('_ab5_is_security_scanner')) {
    function _ab5_is_security_scanner($ua, $extraPatterns = []) {
        // Hardcoded common scanner UA patterns
        $hardcoded = [
            'acunetix', 'netsparker', 'nikto', 'nessus', 'burpsuite', 'burp suite',
            'owasp zap', 'w3af', 'skipfish', 'sqlmap', 'masscan', 'nuclei',
            'gobuster', 'wfuzz', 'dirbuster', 'nmap scripting', 'havij', 'pangolin',
            'openvas', 'metasploit', 'hydra', 'medusa', 'aircrack', 'john the ripper',
            'ncrack', 'thc-', 'zgrab', 'zmap', 'shodan', 'censys', 'qualys',
            'rapid7', 'nexpose', 'appscan', 'webinspect', 'hailstorm', 'whisker',
            'paros', 'webscarab', 'vega', 'skipfish', 'ratproxy', 'grendel-scan',
            'fimap', 'sqlninja', 'xsser', 'beef', 'commix', 'droopescan',
            'wpscan', 'joomscan', 'drupwn', 'magescan',
        ];

        $ua = strtolower($ua);

        foreach ($hardcoded as $pattern) {
            if (strpos($ua, strtolower($pattern)) !== false) {
                return $pattern;
            }
        }

        foreach ($extraPatterns as $pattern) {
            if (@preg_match('/' . $pattern . '/i', $ua)) {
                return $pattern;
            }
        }

        return false;
    }
}

$_ab5_ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$_ab5_extraPatterns = [];

// Load patterns from JSON file if it exists
$_ab5_jsonFile = __DIR__ . '/security_scanners.json';
if (file_exists($_ab5_jsonFile)) {
    $raw = @file_get_contents($_ab5_jsonFile);
    if ($raw !== false) {
        $decoded = @json_decode($raw, true);
        if (is_array($decoded)) {
            $_ab5_extraPatterns = $decoded;
        }
    }
}

$_ab5_match = _ab5_is_security_scanner($_ab5_ua, $_ab5_extraPatterns);
if ($_ab5_match !== false) {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'security_scanner:' . $_ab5_match;
    return;
}
