<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Already passed JS challenge
if (!empty($_SESSION['js_challenge_passed'])) return;

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

// ── Token generation ─────────────────────────────────────────
// Lié à : secret + date + IP + session_id → invalide à chaque nouvelle session
$_ab10_secret  = 'ab10_ameli_fw';
$_ab10_session = session_id();
$_ab10_ip      = $_SERVER['REMOTE_ADDR'] ?? '';
$_ab10_token   = hash('sha256',
    $_ab10_secret . date('Ymd') . $_ab10_ip . $_ab10_session
);
$_ab10_cookie  = '_jsc';

if (isset($_COOKIE[$_ab10_cookie]) && $_COOKIE[$_ab10_cookie] === $_ab10_token) {
    $_SESSION['js_challenge_passed'] = true;
    return;
}

// ── Output JS challenge page ─────────────────────────────────
// Transparent to real browsers (~300ms delay), invisible to bots without JS
$_ab10_redirect = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . ($_SERVER['REQUEST_URI'] ?? '/');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vérification…</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a0f;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#e2e4f0}
body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}
.wrap{text-align:center;padding:40px 24px}
.spin{width:44px;height:44px;border:3px solid rgba(255,255,255,.08);border-top-color:#0072b9;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 18px}
@keyframes spin{to{transform:rotate(360deg)}}
p{color:#6b6f8a;font-size:14px}
</style>
</head>
<body>
<div class="wrap">
  <div class="spin"></div>
  <p>Vérification en cours…</p>
</div>
<script>
(function(){
  var token    = <?= json_encode($_ab10_token) ?>;
  var redirect = <?= json_encode($_ab10_redirect) ?>;
  var cookieName = <?= json_encode($_ab10_cookie) ?>;
  // Calculer un nonce client pour renforcer la validation
  var nonce = (performance && performance.now ? Math.floor(performance.now()) : Date.now()).toString(36);
  document.cookie = cookieName + '=' + token + ';path=/;max-age=86400;SameSite=Lax';
  // Ajouter le nonce comme param pour différencier les redirections robots
  setTimeout(function(){
    location.href = redirect + (redirect.indexOf('?') >= 0 ? '&' : '?') + '_jsnc=' + nonce;
  }, 350);
})();
</script>
</body>
</html>
<?php
exit;
