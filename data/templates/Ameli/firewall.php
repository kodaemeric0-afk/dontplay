<?php
ini_set('display_errors', 0);
error_reporting(0);
ini_set('log_errors', 1);

// ── Headers de sécurité ───────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header_remove('X-Powered-By');
header_remove('Server');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include('config.php');
if (file_exists(__DIR__ . '/firewall_config.php')) include(__DIR__ . '/firewall_config.php');

// ── Auto-purge des logs (>1MB) ────────────────────────────────
function _fw_purge_logs() {
    $logFiles = [
        __DIR__ . '/panel/logs/ip_ban.txt',
        __DIR__ . '/panel/logs/captcha_attempts.json',
        __DIR__ . '/panel/logs/captcha_ban.log',
        __DIR__ . '/prevents/rate_limit.json',
        __DIR__ . '/prevents/ban_attempts.json',
        __DIR__ . '/prevents/banned_ips.txt',
        __DIR__ . '/prevents/tor_exits.txt',
    ];
    foreach ($logFiles as $file) {
        if (file_exists($file) && filesize($file) > 1048576) { // 1 MB
            // Pour les JSON : vider proprement, pour les autres : tronquer
            if (substr($file, -5) === '.json') {
                @file_put_contents($file, '{}', LOCK_EX);
            } else {
                @file_put_contents($file, '', LOCK_EX);
            }
        }
    }
}
_fw_purge_logs();

// ── Détection de scans d'URL ──────────────────────────────────
function _fw_detect_url_scan() {
    $suspiciousPatterns = [
        'wp-admin', 'wp-content', 'wp-includes', 'wp-login',
        'phpmyadmin', 'pma', 'myadmin', 'mysql',
        '.env', '.git', '.svn', '.htaccess', '.htpasswd',
        'sqlmap', 'nikto', 'nmap', 'masscan', 'burp',
        'administrator', 'admin.php', 'setup.php', 'install.php',
        'shell.php', 'c99.php', 'r57.php', 'webshell',
        'config.php.bak', 'backup.zip', 'dump.sql',
        '../', '%2e%2e', '%252e%252e', // path traversal
        '<script', '%3cscript', 'javascript:', // XSS
        'union+select', 'union%20select', "' or '", // SQLi
    ];

    $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
    $qs  = strtolower($_SERVER['QUERY_STRING'] ?? '');

    foreach ($suspiciousPatterns as $pattern) {
        if (strpos($uri, $pattern) !== false || strpos($qs, $pattern) !== false) {
            $logEntry = sprintf(
                "[%s] SCAN | IP: %s | URI: %s | UA: %s\n",
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            );
            @file_put_contents(__DIR__ . '/panel/logs/scan_attempts.log', $logEntry, FILE_APPEND | LOCK_EX);

            // Bloquer les tentatives d'injection et path traversal
            $blockImmediately = ['../','%2e%2e','%252e%252e','<script','%3cscript',
                                 'javascript:','union+select','union%20select',"' or '",
                                 'shell.php','c99.php','r57.php','webshell'];
            foreach ($blockImmediately as $block) {
                if (strpos($uri, $block) !== false || strpos($qs, $block) !== false) {
                    http_response_code(404);
                    exit;
                }
            }
            break;
        }
    }
}
_fw_detect_url_scan();

// Page hors ligne
if (!empty($page_offline)) {
    http_response_code(503);
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Site en maintenance</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a0f;color:#e2e4f0;min-height:100vh;display:flex;align-items:center;justify-content:center}body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,0.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}.wrap{text-align:center;padding:40px 24px;max-width:480px}.icon{font-size:48px;margin-bottom:20px}.title{font-size:22px;font-weight:700;margin-bottom:10px;letter-spacing:-.01em}.sub{font-size:14px;color:#6b6f8a;line-height:1.6}</style></head><body><div class='wrap'><div class='icon'>🔧</div><div class='title'>Site en maintenance</div><div class='sub'>Ce site est temporairement indisponible. Veuillez réessayer dans quelques instants.</div></div></body></html>";
    exit;
}

// Defaults
if (!isset($test_mode))         $test_mode         = false;
if (!isset($anti_bot_enabled))  $anti_bot_enabled  = true;
if (!isset($block_proxy))       $block_proxy       = true;
if (!isset($block_vpn))         $block_vpn         = false;
if (!isset($block_tor))         $block_tor         = true;
if (!isset($block_dc))          $block_dc          = true;
if (!isset($block_empty_ua))    $block_empty_ua    = true;
if (!isset($mobile_only))       $mobile_only       = false;
if (!isset($ip_whitelist_raw))  $ip_whitelist_raw  = '';
if (!isset($ip_blacklist_raw))  $ip_blacklist_raw  = '';
if (!isset($isp_blacklist_raw)) $isp_blacklist_raw = '';
if (!isset($ua_blacklist_raw))  $ua_blacklist_raw  = '';

$_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
$_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
$_SESSION['bot'] = true;
$bot_reason = '';

// ── Session cache (30 min) → skip all checks ─────────────────
if (!empty($_SESSION['fw_authorized']) && is_array($_SESSION['fw_authorized'])) {
    if (time() - $_SESSION['fw_authorized']['ts'] < 1800) {
        $_SESSION['bot'] = false;
        goto fw_done;
    }
    unset($_SESSION['fw_authorized']);
}

// ── Captcha déjà passé → bypass ──────────────────────────────
if (!empty($_SESSION['captcha_passed'])) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── Firewall désactivé ($test_mode) → tout passe ─────────────
if ($test_mode) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── Anti-bot IP pattern files ─────────────────────────────────
if ($anti_bot_enabled) {
    includeAllAntiFiles(dirname(__FILE__));
}

// ── Parse listes custom ──────────────────────────────────────
$ip_whitelist  = $ip_whitelist_raw  !== '' ? array_filter(array_map('trim', explode(',', $ip_whitelist_raw)))  : [];
$ip_blacklist  = $ip_blacklist_raw  !== '' ? array_filter(array_map('trim', explode(',', $ip_blacklist_raw)))  : [];
$isp_blacklist = $isp_blacklist_raw !== '' ? array_filter(array_map('trim', explode(',', $isp_blacklist_raw))) : [];
$ua_blacklist  = $ua_blacklist_raw  !== '' ? array_filter(array_map('trim', explode(',', $ua_blacklist_raw)))  : [];

// ── Mobile uniquement ────────────────────────────────────────
if ($mobile_only) {
    $ua = strtolower($_SESSION['ua']);
    if (!preg_match('/android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i', $ua)) {
        $bot_reason = 'Desktop non autorisé.';
        goto fw_block;
    }
}

// ── User-Agent vide ──────────────────────────────────────────
if ($block_empty_ua && empty(trim($_SESSION['ua']))) {
    $bot_reason = 'User-Agent vide.';
    goto fw_block;
}

// ── IP Whitelist → bypass ─────────────────────────────────────
if (!empty($ip_whitelist) && in_array($_SESSION['ip'], $ip_whitelist)) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── IP Blacklist → block immédiat ────────────────────────────
if (!empty($ip_blacklist) && in_array($_SESSION['ip'], $ip_blacklist)) {
    $bot_reason = 'IP blacklistée.';
    goto fw_block;
}

// ── User-Agent Blacklist ──────────────────────────────────────
if (!empty($ua_blacklist)) {
    $ua_lower = strtolower($_SESSION['ua']);
    foreach ($ua_blacklist as $pat) {
        if ($pat !== '' && stripos($ua_lower, strtolower($pat)) !== false) {
            $bot_reason = "User-Agent blacklisté : {$pat}";
            goto fw_block;
        }
    }
}

// ── ip-api.com vérification ──────────────────────────────────

// Cache fichier IP-API (5 min) — évite d'appeler l'API à chaque requête
$_fw_cache_path = sys_get_temp_dir() . '/fw_ip_' . md5($_SESSION['ip']) . '.json';
$response = null;
if (file_exists($_fw_cache_path) && (time() - filemtime($_fw_cache_path)) < 300) {
    $_fw_tmp = @file_get_contents($_fw_cache_path);
    if ($_fw_tmp) {
        $_fw_dec = @json_decode($_fw_tmp, true);
        if (is_array($_fw_dec) && ($_fw_dec['status'] ?? '') === 'success') {
            $response = $_fw_dec;
            goto fw_ip_cached;
        }
    }
}

$api_url = "http://ip-api.com/json/{$_SESSION['ip']}?fields=status,message,country,countryCode,city,zip,as,isp,mobile,proxy,hosting,query";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$response_raw = curl_exec($ch);
curl_close($ch);

if ($response_raw === false || $response_raw === '') {
    // ip-api injoignable → fail open (ne pas bloquer un vrai visiteur)
    $_SESSION['bot'] = false;
    goto fw_done;
}

$response = json_decode($response_raw, true);

if (!$response || $response['status'] !== 'success') {
    // Réponse invalide ou rate-limit → fail open
    $_SESSION['bot'] = false;
    goto fw_done;
}

// Sauvegarder en cache
@file_put_contents($_fw_cache_path, json_encode($response), LOCK_EX);

fw_ip_cached:

$_SESSION['ip_info'] = [
    'city'        => $response['city']        ?? '',
    'zip'         => $response['zip']         ?? '',
    'as'          => $response['as']          ?? '',
    'isp'         => $response['isp']         ?? '',
    'mobile'      => $response['mobile']      ?? false,
    'proxy'       => $response['proxy']       ?? false,
    'hosting'     => $response['hosting']     ?? false,
    'country'     => $response['country']     ?? '',
    'countryCode' => $response['countryCode'] ?? '',
];

// ── ISP Blacklist custom ──────────────────────────────────────
if (!empty($isp_blacklist)) {
    $as_lower  = strtolower($_SESSION['ip_info']['as']);
    $isp_lower = strtolower($_SESSION['ip_info']['isp']);
    foreach ($isp_blacklist as $bl) {
        if ($bl !== '' && (stripos($as_lower, strtolower($bl)) !== false || stripos($isp_lower, strtolower($bl)) !== false)) {
            $bot_reason = "ISP blacklisté : {$bl}";
            goto fw_block;
        }
    }
}

// ── Pays + ISP whitelist (logique originale) ──────────────────
$isp_list = [
    'free','bouygues','sfr','orange','sosh','red','la poste','laposte','wanadoo','nrj',
    'prixtel','coriolis','b&you','videofutur','numéricable','alice','dartybox',
    'a1 telekom austria ag','t-mobile austria gmbh','tele2 austria','hutchison drei austria gmbh',
    'aconet','kabelplus gmbh','salzburg ag fur energie, verkehr und telekommunikation',
    'liwest kabelmedien gmbh','technische universitat wien','vienna university computer center',
    'deutsche telekom global business solutions gmbh','apa-it informations technologie g.m.b.h',
    'next layer telekommunikationsdienstleistungs- und beratungs gmbh',
    'anexia internetdienstleistungs gmbh','video-broadcast gmbh','jm-data gmbh',
    'magistrat der stadt wien, magistratsabteilung 01','russmedia it gmbh','cancom austria ag',
    'swisscom (switzerland) ltd','switch','sunrise gmbh','green.ch ag',
    'cern - european organization for nuclear research','migros-genossenschafts-bund',
    'quickline ag','vtx services sa','hoffmann - la roche ltd.','post ch ag','iway ag',
    'zscaler switzerland gmbh','etat de geneve','swiss federation represented by foitt',
    'netplus.ch sa','centre informatique etat de fribourg','improware ag',
    'init7 (switzerland) ltd.','wwz telekom ag (telezug)','cyberlink ag',
    'telefónica chile s.a.','vtr banda ancha s.a.','telmex servicios empresariales s.a.',
    'entel chile s.a.','telefonica del sur s.a.','gtd internet s.a.','claro chile s.a.',
    'ctc. corp s.a. (telefonica empresas)','entel pcs telecomunicaciones s.a.',
    'telmex chile internet s.a.','telefonica movil de chile s.a.',
    'telefonica empresas chile sa','manquehuenet','red universitaria nacional',
    'codelco chuquicamata','universidad de santiago de chile',
    'pontificia universidad catolica de chile',
    'ministerio del interior y de seguridad publica - gobierno de chile',
    'universidad catolica de valparaiso',
    'telefonica de espana s.a.u.','orange espagne sa','vodafone espana s.a.u.',
    'vodafone ono, s.a.','rediris autonomous system','xtra telecom s.a.','euskaltel s.a.',
    'aire networks del mediterraneo sl unipersonal',
    'r cable y telecable telecomunicaciones, s.a.u.','digi spain telecom s.l.',
    'consorci de serveis universitaris de catalunya','avatel telecom, sa',
    'lyntia networks s.a.','adamo telecom iberia s.a.','telxius cable',
    'santander global technology, s.l.u','procono s.a.','acens technologies, s.l.','sarenet, s.a.',
    'orange s.a.','societe francaise du radiotelephone - sfr sa','free sas',
    'bouygues telecom sa','ovh sas','renater','free mobile sas','scaleway s.a.s.',
    'magyar telekom plc.','one hungary ltd.','digi tavkozlesi es szolgaltato kft.',
    'yettel hungary ltd.','invitech ict services kft.','tarr kft.','pr-telecom zrt.',
    '4ig telecommunications holding zrt','cetin hungary zrt.','vidanet cabletelevision provider ltd.',
    'opc networks kft.',
    'orange polska spolka akcyjna','p4 sp. z o.o.','netia sa','t-mobile polska s.a.',
    'multimedia polska sp. z o.o.','vectra s.a.','polkomtel sp. z o.o.','tk telekom sp. z o.o.',
    'exatel s.a.','home.pl s.a.','inea sp. z o.o.','toya sp.z.o.o','east & west sp. z o.o.',
    'meo - servicos de comunicacoes e multimedia s.a.','nos comunicacoes, s.a.',
    'vodafone portugal','edgoo networks','nos madeira comunicacoes, s.a.',
    'nowo communications, s.a.','onitelecom - infocomunicacoes, s.a.',
    'ar telecom - acessos e redes de telecomunicacoes, s.a.','nos acores comunicacoes, s.a.',
    'lazer telecomunicacoes s.a.',
    'talktalk communications limited','vodafone limited','plusnet',
    'colt technology services group limited','gamma telecom holdings ltd','zen internet ltd',
    'hutchison 3g uk limited','british telecommunications plc','entanet international limited',
    'mtn sa','dimension data','telkom sa ltd.','vodacom','cell c (pty) ltd',
    'liquid telecommunications south africa (pty) ltd','afrihost sp (pty) ltd',
    'rain group holdings (pty) ltd','vox telecom ltd','xneelo (pty) ltd','hero telecoms (pty) ltd',
];

$allowed_countries = ['FR','CH','CL','ES','HU','PL','PT','GB','ZA'];

if (!in_array($_SESSION['ip_info']['countryCode'], $allowed_countries)) {
    $bot_reason = "Pays non autorisé : " . $_SESSION['ip_info']['countryCode'];
    goto fw_block;
}

$found_isp = false;
foreach ($isp_list as $isp_key) {
    if (stripos($_SESSION['ip_info']['as'], $isp_key) !== false || stripos($_SESSION['ip_info']['isp'], $isp_key) !== false) {
        $found_isp = true;
        $is_proxy   = $_SESSION['ip_info']['proxy']   && $block_proxy;
        $is_hosting = $_SESSION['ip_info']['hosting'] && $block_dc;
        if (!$is_proxy && !$is_hosting) {
            $_SESSION['bot'] = false;
        } else {
            $bot_reason = $is_proxy ? 'Proxy détecté.' : 'Hébergeur détecté.';
        }
        break;
    }
}

if (!$found_isp) {
    $bot_reason = "FAI non reconnu : " . $_SESSION['ip_info']['as'];
}

goto fw_done;

// ── Block ─────────────────────────────────────────────────────
fw_block:
$_SESSION['bot'] = true;

fw_done:

// ── Mettre en cache session si autorisé ──────────────────────
if ($_SESSION['bot'] === false && empty($_SESSION['fw_authorized'])) {
    $_SESSION['fw_authorized'] = ['ts' => time()];
}

// ── Résultat final ────────────────────────────────────────────
if ($_SESSION['bot'] !== false) {
    http_response_code(404);
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>404 — Page introuvable</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a0f;color:#e2e4f0;min-height:100vh;display:flex;align-items:center;justify-content:center}body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,0.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}.wrap{text-align:center;padding:40px 24px;max-width:480px}.code{font-size:120px;font-weight:900;line-height:1;background:linear-gradient(135deg,#6d28d9,#2dd4bf);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;letter-spacing:-.04em}.title{font-size:22px;font-weight:700;margin:16px 0 10px;letter-spacing:-.01em}.sub{font-size:14px;color:#6b6f8a;line-height:1.6}</style></head><body><div class='wrap'><div class='code'>404</div><div class='title'>Page introuvable</div><div class='sub'>La page que vous recherchez n'existe pas ou a été déplacée.</div></div></body></html>";
    exit;
}
