<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/*
  Utilisation :
  <?php echo $tr['menu']['home']; ?>
  <?php echo $tr['title']; ?>
*/

function detectBrowserLanguage($availableLanguages, $default = 'en') {
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return $default;
    }

    $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
    foreach ($langs as $lang) {
        $langCode = substr($lang, 0, 2);
        if (in_array($langCode, $availableLanguages)) {
            return $langCode;
        }
    }
    return $default;
}

// Chargement des traductions
$langData = json_decode(file_get_contents(__DIR__ . '/capchat.json'), true);

// Langue disponible
$availableLanguages = array_keys($langData);

// Vérifie s'il y a une langue dans l'URL
if (isset($_GET['lang']) && in_array($_GET['lang'], $availableLanguages)) {
    $_SESSION['lang'] = $_GET['lang'];
}

// Si aucune langue en session, détecte celle du navigateur
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = detectBrowserLanguage($availableLanguages);
}

// Sélection de la langue
$lang = $_SESSION['lang'];
$tr = $langData[$lang] ?? $langData['en'];
$langCode = $lang;
?>
