<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('detectBrowserLanguage')) {
    function detectBrowserLanguage(array $availableLanguages, string $default = 'en'): string {
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return $default;
        }
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($langs as $lang) {
            $code = substr(trim($lang), 0, 2);
            if (in_array($code, $availableLanguages, true)) {
                return $code;
            }
        }
        return $default;
    }
}

$countryToLang = [
    'FR' => 'fr', 'BE' => 'fr', 'CH' => 'fr', 'LU' => 'fr', 'MC' => 'fr',
    'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CO' => 'es', 'CL' => 'es',
    'DE' => 'de', 'AT' => 'de',
    'IT' => 'it',
    'PT' => 'pt', 'BR' => 'pt',
    'NL' => 'nl',
    'PL' => 'pl',
    'RU' => 'ru',
    'SE' => 'sv',
    'NO' => 'no',
    'DK' => 'da',
    'FI' => 'fi',
    'CZ' => 'cs',
    'SK' => 'sk',
    'HU' => 'hu',
    'RO' => 'ro',
    'BG' => 'bg',
    'HR' => 'hr',
    'SI' => 'sl',
    'EE' => 'et',
    'LV' => 'lv',
    'LT' => 'lt',
    'MT' => 'mt',
    'GB' => 'en', 'US' => 'en', 'CA' => 'en', 'AU' => 'en', 'IE' => 'en',
    'CY' => 'cy',
];

$langData = json_decode(file_get_contents(__DIR__ . '/lang.json'), true);
$availableLanguages = array_keys($langData);

// 1. Paramètre URL
if (isset($_GET['lang']) && in_array($_GET['lang'], $availableLanguages, true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

// 2. Pas encore de langue → détection par IP puis navigateur
if (!isset($_SESSION['lang'])) {
    $countryCode = $_SESSION['ip_info']['countryCode'] ?? '';
    if ($countryCode && isset($countryToLang[$countryCode])) {
        $detected = $countryToLang[$countryCode];
        if (in_array($detected, $availableLanguages, true)) {
            $_SESSION['lang'] = $detected;
        }
    }
    if (!isset($_SESSION['lang'])) {
        $_SESSION['lang'] = detectBrowserLanguage($availableLanguages);
    }
}

$lang     = $_SESSION['lang'];
$tr       = $langData[$lang] ?? $langData['en'];
$langCode = $lang;

