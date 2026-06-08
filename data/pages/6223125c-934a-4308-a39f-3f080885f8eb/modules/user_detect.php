<?php


if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/load_config.php';
require_once __DIR__ . '/../antibots/concurrent_manager.php';
require_once __DIR__ . '/../antibots/monitoring/performance_monitor.php';
require_once __DIR__ . '/../antibots/monitoring/integration_manager.php';
if (!function_exists('isBotUserAgent')) {
    function isBotUserAgent(): bool {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Vérification stricte des User-Agents vides ou suspects
        if (empty($userAgent) || $userAgent === '-' || $userAgent === 'unknown') {
            return true;
        }
        
        // Vérification de la longueur (trop court = suspect)
        if (strlen($userAgent) < 10) {
            return true;
        }
        
        // Patterns de bots critiques (priorité haute) - RENFORCÉ
        $criticalBots = [
            '/bot/i', '/crawl/i', '/spider/i', '/curl/i', '/wget/i',
            '/python/i', '/java/i', '/go-http/i', '/libwww/i',
            '/headless/i', '/phantom/i', '/selenium/i', '/puppeteer/i',
            '/playwright/i', '/mechanize/i', '/scrapy/i', '/requests/i',
            '/httpclient/i', '/okhttp/i', '/axios/i',
            '/postman/i', '/insomnia/i', '/httpie/i', '/restsharp/i',
            '/nmap/i', '/nikto/i', '/sqlmap/i', '/burp/i', '/zap/i',
            '/masscan/i', '/nuclei/i', '/gobuster/i', '/dirb/i',
            '/facebookexternalhit/i', '/twitterbot/i', '/linkedinbot/i',
            '/googlebot/i', '/bingbot/i', '/yandexbot/i', '/baiduspider/i',
            '/semrushbot/i', '/ahrefsbot/i', '/mj12bot/i', '/dotbot/i',
            // NOUVEAUX PATTERNS POUR BOTS SOPHISTIQUÉS
            '/\(selenium\)/i', '/\(puppeteer\)/i', '/\(playwright\)/i',
            '/\(headless\)/i', '/\(phantomjs\)/i', '/\(mechanize\)/i',
            '/\(scrapy\)/i', '/\(requests\)/i', '/\(httpclient\)/i',
            '/\(axios\)/i', '/\(restsharp\)/i',
            '/\(aiohttp\)/i', '/\(httpx\)/i', '/\(urllib\)/i',
            '/\(node\)/i', '/\(got\)/i', '/\(superagent\)/i',
            '/\(needle\)/i', '/\(unirest\)/i', '/\(request\)/i'
        ];
        
        foreach ($criticalBots as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }
        
        // Vérification de cohérence des headers
        if (!isset($_SERVER['HTTP_ACCEPT']) || empty($_SERVER['HTTP_ACCEPT'])) {
            return true; // Pas d'Accept header = suspect
        }
        
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) || empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return true; // Pas de langue = suspect
        }
        
        return false;
    }
}

if (!function_exists('exitWithBlock')) {
    function exitWithBlock(string $reason): void {
        http_response_code(403);
        echo "<h1>403 - Accès refusé</h1><p>" . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</p>";
        exit;
    }
}

if (!function_exists('getUserIP')) {
    function getUserIP(): string {
        $ipHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim(end($ips));
            }
        }
        return 'UNKNOWN';
    }
}


class MobileDetect {
    protected string $userAgent;
    protected string $accept;
    protected bool $isMobile = false;
    protected bool $isTablet = false;

    protected array $devices = [
        'android'           => 'android',
        'blackberry'        => 'blackberry|rim[0-9]+',
        'iphone'            => 'iphone',
        'ipod'              => 'ipod',
        'opera'             => 'opera mini|opera mobi',
        'palm'              => '(avantgo|blazer|elaine|hiptop|palm|plucker|xiino)',
        'windows_phone'     => 'windows phone',
        'windows_mobile'    => 'windows ce; (iemobile|ppc|smartphone)',
        'kindle'            => 'kindle|silk',
        'nokia'             => 'nokia|series ?[0-9]+',
        'symbian'           => 'symbian|symbos',
        'maemo'             => 'maemo',
        'fennec'            => 'fennec',
        'bb10'              => 'bb10|playbook|blackberry.*version\/10',
        'meego'             => 'meego',
        'mobile_safari'     => 'mobile safari',
        'tizen'             => 'tizen',
        'webos'             => 'webos|hpwos',
        'bada'              => 'bada',
        'huawei'            => 'huawei|honor',
        'xiaomi'            => 'redmi|mi\\s|xiaomi|poco',
        'oppo'              => 'oppo',
        'vivo'              => 'vivo',
        'realme'            => 'realme',
        'oneplus'           => 'oneplus',
        'sony'              => 'sonyericsson|xperia',
        'lg'                => 'lg[\\-\\/]|lg\\s|lg[0-9]+',
        'htc'               => 'htc|desire|sensation|wildfire|hero',
        'motorola'          => 'moto|mot\\-|droid|xt[0-9]+',
        'samsung'           => 'samsung|galaxy|gt\\-|sm\\-',
        'zte'               => 'zte|blade',
        'alcatel'           => 'alcatel|one\\stouch',
        'asus'              => 'asus|zenfone',
        'generic'           => '(mobile|mmp|midp|pda|pocket|psp|symbian|treo|up.browser|up.link|vodafone|wap|phone|smartphone)',
    ];

    protected array $tablets = [
        'ipad'              => 'ipad',
        'android_tablet'    => 'android(?!.*mobile)',
        'kindle'            => 'kindle|silk',
        'nexus_tablet'      => 'nexus\\s[0-9]+|nexus 7|nexus 10|nexus 9',
        'playbook'          => 'playbook',
        'xoom'              => 'xoom|sch-i800',
        'galaxy_tab'        => 'sm\\-t[0-9]+|sm\\-t\\w+|galaxy tab|tab\\s[0-9]+',
        'surface'           => 'surface\\srt|surface|windows nt [0-9.]+; arm; tablet',
        'hp_tablet'         => 'hp\\stablet|touchpad',
        'lenovo_tablet'     => 'thinkpad|ideatab',
        'dell_tablet'       => 'venue|streak',
        'yarvik_tablet'     => 'yarvik',
        'medion_tablet'     => 'medion',
        'arnova_tablet'     => 'arnova',
        'archos_tablet'     => 'archos',
        'aoc_tablet'        => 'aoc\\s',
        'bq_tablet'         => 'bq\\s',
        'tesco_tablet'      => 'tesco',
        'le_pan_tablet'     => 'le\\span',
        'fujitsu_tablet'    => 'stylistic',
        'qmv_tablet'        => 'qmv7a',
        'odys_tablet'       => 'loox',
        'captiva_tablet'    => 'captiva',
        'iconbit_tablet'    => 'netTAB|ultraTAB',
        'teclast_tablet'    => 'teclast',
        'onda_tablet'       => 'onda',
        'jxd_tablet'        => 'jxd',
        'pointofview_tablet'=> 'pointofview',
        'overmax_tablet'    => 'overmax',
        'barnesandnoble'    => 'bn\\srv[0-9]+',
        'generic_tablet'    => '(tablet|tab)[^a-z]|tablet pc',
    ];

    public function __construct() {
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        if ($this->hasWapProfile() || $this->hasWapAccept()) {
            $this->isMobile = true;
        } else {
            $this->detectTablet();
            if (!$this->isTablet) {
                $this->detectMobile();
            }
        }
    }

    protected function hasWapProfile(): bool {
        return isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE']);
    }

    protected function hasWapAccept(): bool {
        return stripos($this->accept, 'text/vnd.wap.wml') !== false
            || stripos($this->accept, 'application/vnd.wap.xhtml+xml') !== false;
    }

    protected function detectTablet(): void {
        foreach ($this->tablets as $pattern) {
            if (preg_match("/$pattern/i", $this->userAgent)) {
                $this->isTablet = true;
                break;
            }
        }
    }

    protected function detectMobile(): void {
        foreach ($this->devices as $pattern) {
            if (preg_match("/$pattern/i", $this->userAgent)) {
                $this->isMobile = true;
                break;
            }
        }
    }

    public function isMobile(): bool {
        return $this->isMobile;
    }

    public function isTablet(): bool {
        return $this->isTablet;
    }

    public function isDesktop(): bool {
        return !$this->isMobile && !$this->isTablet;
    }

    public function getDeviceType(): string {
        if ($this->isTablet()) return 'tablet';
        if ($this->isMobile()) return 'mobile';
        return 'desktop';
    }

    public function getUserAgent(): string {
        return $this->userAgent;
    }

    public function getDeviceModel(): string {
    $ua = strtolower($this->userAgent);

    // Détection spéciale pour Samsung basée sur des indices supplémentaires
    if (preg_match('/android.*mobile/', $ua) && !preg_match('/sm-[a-z0-9\-]+|samsung|galaxy/', $ua)) {
        // Si c'est un mobile Android sans code Samsung spécifique, 
        // on peut essayer de deviner que c'est un Samsung basé sur d'autres indices
        // ou retourner un Android générique plus informatif
        return 'Android Mobile Device';
    }

    $patterns = [
        // Smartphones & Tablettes - Samsung en priorité avec modèles spécifiques
        'Samsung Galaxy S' => '/sm-s[0-9]+[a-z]*/',  // Galaxy S series (S21, S22, etc.)
        'Samsung Galaxy Note' => '/sm-n[0-9]+[a-z]*/',  // Galaxy Note series
        'Samsung Galaxy A' => '/sm-a[0-9]+[a-z]*/',  // Galaxy A series
        'Samsung Galaxy Z' => '/sm-f[0-9]+[a-z]*/',  // Galaxy Z Fold/Flip series
        'Samsung Galaxy Tab' => '/sm-t[0-9]+[a-z]*/',  // Galaxy Tab series
        'Samsung Galaxy' => '/sm-[a-z0-9\-]+/',  // Autres modèles Samsung
        'Samsung Mobile' => '/samsung|galaxy/',  // Fallback pour Samsung sans code modèle
        
        // iPhone avec modèles spécifiques
        'iPhone 15 Pro Max' => '/iphone.*os\s*17_/',
        'iPhone 15 Pro' => '/iphone.*os\s*17_/',
        'iPhone 15 Plus' => '/iphone.*os\s*17_/',
        'iPhone 15' => '/iphone.*os\s*17_/',
        'iPhone 14 Pro Max' => '/iphone.*os\s*16_/',
        'iPhone 14 Pro' => '/iphone.*os\s*16_/',
        'iPhone 14 Plus' => '/iphone.*os\s*16_/',
        'iPhone 14' => '/iphone.*os\s*16_/',
        'iPhone 13 Pro Max' => '/iphone.*os\s*15_/',
        'iPhone 13 Pro' => '/iphone.*os\s*15_/',
        'iPhone 13' => '/iphone.*os\s*15_/',
        'iPhone 12 Pro Max' => '/iphone.*os\s*14_/',
        'iPhone 12 Pro' => '/iphone.*os\s*14_/',
        'iPhone 12' => '/iphone.*os\s*14_/',
        'iPhone SE' => '/iphone.*os\s*1[4-7]_/',
        'iPhone' => '/iphone(?:\sos\s)?[\d_]+/',  // Fallback général iPhone
        
        // iPad avec modèles spécifiques
        'iPad Pro' => '/ipad.*os\s*1[6-7]_/',
        'iPad Air' => '/ipad.*os\s*1[6-7]_/',
        'iPad mini' => '/ipad.*os\s*1[6-7]_/',
        'iPad' => '/ipad(?:.*os\s)?[\d_]+/',  // Fallback général iPad
        
        // Xiaomi avec modèles spécifiques
        'Xiaomi Redmi Note' => '/redmi\snote\s[a-z0-9\-]+/',
        'Xiaomi Redmi' => '/redmi\s[a-z0-9\-]+/',
        'Xiaomi Mi' => '/mi\s[a-z0-9\-]+/',
        'Xiaomi POCO' => '/poco\s[a-z0-9\-]+/',
        'Xiaomi' => '/xiaomi|mi\s|redmi/',
        
        // Google Pixel avec modèles spécifiques
        'Google Pixel 8 Pro' => '/pixel\s8\spro/',
        'Google Pixel 8' => '/pixel\s8/',
        'Google Pixel 7 Pro' => '/pixel\s7\spro/',
        'Google Pixel 7' => '/pixel\s7/',
        'Google Pixel 6 Pro' => '/pixel\s6\spro/',
        'Google Pixel 6' => '/pixel\s6/',
        'Google Pixel' => '/pixel\s[0-9a-z\-]+/',
        
        // OnePlus avec modèles spécifiques
        'OnePlus 12' => '/oneplus\s12/',
        'OnePlus 11' => '/oneplus\s11/',
        'OnePlus 10 Pro' => '/oneplus\s10\spro/',
        'OnePlus 10' => '/oneplus\s10/',
        'OnePlus 9 Pro' => '/oneplus\s9\spro/',
        'OnePlus 9' => '/oneplus\s9/',
        'OnePlus' => '/oneplus\s[a-z0-9\-]+/',
        
        // Motorola avec modèles spécifiques
        'Motorola Edge' => '/moto\s?edge\s[a-z0-9\-]+/',
        'Motorola G' => '/moto\s?g\s[a-z0-9\-]+/',
        'Motorola' => '/moto\s?[a-z0-9\-]+/',
        
        // Huawei avec modèles spécifiques
        'Huawei P' => '/huawei\s?p[0-9]+[a-z]*/',
        'Huawei Mate' => '/huawei\s?mate\s[a-z0-9\-]+/',
        'Huawei Nova' => '/huawei\s?nova\s[a-z0-9\-]+/',
        'Huawei' => '/(vog-l29|ane-lx1|mar-lx1a|huawei\s[a-z0-9\-]+)/',
        
        // Sony Xperia avec modèles spécifiques
        'Sony Xperia 1' => '/xperia\s1\s[a-z0-9\-]+/',
        'Sony Xperia 5' => '/xperia\s5\s[a-z0-9\-]+/',
        'Sony Xperia 10' => '/xperia\s10\s[a-z0-9\-]+/',
        'Sony Xperia' => '/xperia\s[a-z0-9\-]+/',
        
        // LG avec modèles spécifiques
        'LG G' => '/lg[\-\/\s]?g[0-9]+[a-z]*/',
        'LG V' => '/lg[\-\/\s]?v[0-9]+[a-z]*/',
        'LG' => '/lg[\-\/\s]?[a-z0-9\-]+/',
        
        // HTC avec modèles spécifiques
        'HTC U' => '/htc[\-\/\s]?u[a-z0-9\-]+/',
        'HTC Desire' => '/htc[\-\/\s]?desire\s[a-z0-9\-]+/',
        'HTC' => '/htc[\-\/\s]?[a-z0-9\-]+/',
        
        // Autres marques avec modèles spécifiques
        'BlackBerry' => '/blackberry\s?[a-z0-9\-]+/',
        'Nokia' => '/nokia\s?[a-z0-9\-]+/',
        'Asus Zenfone' => '/asus\s?zenfone\s[a-z0-9\-]+/',
        'Asus' => '/asus\s?[a-z0-9\-]+/',
        'Realme' => '/realme\s[a-z0-9\-]+/',
        'Vivo' => '/vivo\s[a-z0-9\-]+/',
        'Oppo' => '/oppo\s[a-z0-9\-]+/',
        'Lenovo' => '/lenovo\s[a-z0-9\-]+/',
        'Alcatel' => '/alcatel\s[a-z0-9\-]+/',
        'ZTE' => '/zte\s[a-z0-9\-]+/',
        'Amazon Kindle' => '/kindle|silk/',
        'Meizu' => '/meizu\s?[a-z0-9\-]+/',
        'Google Nexus' => '/nexus\s[0-9]+/',

        // PC / Desktop
        'Windows PC' => '/windows nt ([0-9\.]+)/',              
        'Macintosh' => '/macintosh; intel mac os x ([0-9_\.]+)/',
        'Ubuntu' => '/ubuntu/',                                  
        'Fedora' => '/fedora/',                                  
        'Debian' => '/debian/',                                  
        'Chrome OS' => '/cros/',                                 

        // Consoles de jeu
        'PlayStation 5' => '/playstation 5/',                    
        'PlayStation 4' => '/playstation 4/',                    
        'Xbox Series X' => '/xbox series x/',                    
        'Xbox One' => '/xbox one/',                              
        'Nintendo Switch' => '/nintendo switch/',               

        // TV & Box
        'Samsung Smart TV' => '/smart-tv|smarttv|smarttv samsung/',  
        'LG Smart TV' => '/lg smarttv|webos/',                   
        'Sony TV' => '/bravia|sony tv/',                         
        'Amazon Fire TV' => '/aftt|fire tv/',                    
        'Roku' => '/roku/',                                       
        'Apple TV' => '/apple tv/',                               

        // Robots, crawlers (optionnel)
        'Googlebot' => '/googlebot/',                            
        'Bingbot' => '/bingbot/',                                
        'Baiduspider' => '/baiduspider/',                        

        // Autres appareils
        'Kindle' => '/kindle/',                                  
        'Chromebook' => '/cros/',                                
        'Wear OS' => '/wear os|android wear/',                   

        // Tablettes Android génériques
        'Android Tablet' => '/android(?!.*mobile)/',             

        // Smartwatch (exemples)
        'Apple Watch' => '/applewatch/',                          
        'Samsung Gear' => '/sm-r[0-9]+/',                         

        // Autres OS mobiles
        'Windows Phone' => '/windows phone/',                     
        'BlackBerry OS' => '/blackberry/',                        

        // Autres marques possibles
        'Fairphone' => '/fairphone/',                             
        'BQ' => '/bq[a-z0-9\-]+/',                                
        'Cat Phone' => '/cat[ ]?phone/',                          

        // Général fallback pour Android devices
        'Android Mobile' => '/android.*mobile/',                 
        'Android' => '/android/',                                 
        'Linux' => '/linux/',  // En dernier pour éviter les faux positifs
    ];

    foreach ($patterns as $brand => $pattern) {
        if (preg_match($pattern, $ua, $matches)) {
            $modelRaw = trim($matches[0]);

            // Cas spéciaux Windows et Mac avec version
            if ($brand === 'Windows PC' && isset($matches[1])) {
                return "Windows PC NT " . $matches[1];
            }
            if ($brand === 'Macintosh' && isset($matches[1])) {
                $version = str_replace('_', '.', $matches[1]);
                return "Macintosh macOS " . $version;
            }

            // Extraction de modèles spécifiques pour Samsung
            if (strpos($brand, 'Samsung Galaxy') === 0) {
                $modelCode = strtoupper($modelRaw);
                $modelName = $this->getSamsungModelName($modelCode);
                return $brand . ' ' . $modelName;
            }

            // Extraction de modèles spécifiques pour iPhone
            if (strpos($brand, 'iPhone') === 0) {
                $iosVersion = $this->extractIOSVersion($ua);
                if ($iosVersion) {
                    return $brand . ' (iOS ' . $iosVersion . ')';
                }
            }

            // Extraction de modèles spécifiques pour iPad
            if (strpos($brand, 'iPad') === 0) {
                $iosVersion = $this->extractIOSVersion($ua);
                if ($iosVersion) {
                    return $brand . ' (iPadOS ' . $iosVersion . ')';
                }
            }

            // Extraction d'informations Android supplémentaires
            if (strpos($brand, 'Android') === 0 || preg_match('/android/i', $ua)) {
                $androidInfo = $this->extractAndroidInfo($ua);
                if ($androidInfo) {
                    return $brand . ' ' . $androidInfo;
                }
            }

            // Nettoyage du résultat pour les autres marques
            $modelClean = preg_replace('/\s+/', ' ', $modelRaw);
            $modelClean = strtoupper($modelClean);

            return $brand . ' ' . $modelClean;
        }
    }

    return 'Unknown';
}

    /**
     * Extrait la version iOS/iPadOS du User Agent
     */
    private function extractIOSVersion(string $ua): ?string {
        if (preg_match('/os\s*([0-9_]+)/i', $ua, $matches)) {
            return str_replace('_', '.', $matches[1]);
        }
        return null;
    }

    /**
     * Convertit un code modèle Samsung en nom lisible
     */
    private function getSamsungModelName(string $modelCode): string {
        $samsungModels = [
            // Galaxy S Series
            'SM-S901' => 'S22',
            'SM-S906' => 'S22+',
            'SM-S908' => 'S22 Ultra',
            'SM-S911' => 'S23',
            'SM-S916' => 'S23+',
            'SM-S918' => 'S23 Ultra',
            'SM-S921' => 'S24',
            'SM-S926' => 'S24+',
            'SM-S928' => 'S24 Ultra',
            
            // Galaxy Note Series
            'SM-N970' => 'Note 10',
            'SM-N975' => 'Note 10+',
            'SM-N980' => 'Note 20',
            'SM-N985' => 'Note 20 Ultra',
            
            // Galaxy A Series
            'SM-A125' => 'A12',
            'SM-A135' => 'A13',
            'SM-A145' => 'A14',
            'SM-A205' => 'A20',
            'SM-A215' => 'A21',
            'SM-A225' => 'A22',
            'SM-A235' => 'A23',
            'SM-A305' => 'A30',
            'SM-A315' => 'A31',
            'SM-A325' => 'A32',
            'SM-A335' => 'A33',
            'SM-A405' => 'A40',
            'SM-A415' => 'A41',
            'SM-A425' => 'A42',
            'SM-A435' => 'A43',
            'SM-A505' => 'A50',
            'SM-A515' => 'A51',
            'SM-A525' => 'A52',
            'SM-A535' => 'A53',
            'SM-A605' => 'A60',
            'SM-A705' => 'A70',
            'SM-A715' => 'A71',
            'SM-A725' => 'A72',
            'SM-A735' => 'A73',
            'SM-A805' => 'A80',
            'SM-A905' => 'A90',
            
            // Galaxy Z Series (Fold/Flip)
            'SM-F700' => 'Z Flip',
            'SM-F707' => 'Z Flip 5G',
            'SM-F711' => 'Z Flip 3',
            'SM-F721' => 'Z Flip 4',
            'SM-F731' => 'Z Flip 5',
            'SM-F900' => 'Z Fold',
            'SM-F907' => 'Z Fold 5G',
            'SM-F911' => 'Z Fold 3',
            'SM-F921' => 'Z Fold 4',
            'SM-F931' => 'Z Fold 5',
            
            // Galaxy Tab Series
            'SM-T290' => 'Tab A 8.0',
            'SM-T295' => 'Tab A 8.0 LTE',
            'SM-T500' => 'Tab A7',
            'SM-T505' => 'Tab A7 LTE',
            'SM-T510' => 'Tab A 10.1',
            'SM-T515' => 'Tab A 10.1 LTE',
            'SM-T720' => 'Tab S6 Lite',
            'SM-T730' => 'Tab S7',
            'SM-T735' => 'Tab S7 LTE',
            'SM-T830' => 'Tab S4',
            'SM-T835' => 'Tab S4 LTE',
            'SM-T870' => 'Tab S7+',
            'SM-T875' => 'Tab S7+ LTE',
            'SM-T970' => 'Tab S8',
            'SM-T975' => 'Tab S8+',
            'SM-T978' => 'Tab S8 Ultra',
        ];

        return $samsungModels[$modelCode] ?? $modelCode;
    }

    /**
     * Extrait des informations Android (version, modèle, etc.)
     */
    private function extractAndroidInfo(string $ua): ?string {
        $info = [];
        
        // Version Android
        if (preg_match('/android\s*([0-9\.]+)/i', $ua, $matches)) {
            $version = $matches[1];
            $androidNames = [
                '14' => '14 (UpsideDownCake)',
                '13' => '13 (Tiramisu)',
                '12' => '12 (Snow Cone)',
                '11' => '11 (Red Velvet Cake)',
                '10' => '10 (Quince Tart)',
                '9' => '9 (Pie)',
                '8' => '8 (Oreo)',
                '7' => '7 (Nougat)',
                '6' => '6 (Marshmallow)',
                '5' => '5 (Lollipop)',
            ];
            
            $majorVersion = explode('.', $version)[0];
            $info[] = 'Android ' . ($androidNames[$majorVersion] ?? $version);
        }
        
        // Modèle spécifique si détecté
        if (preg_match('/build\/([a-z0-9\-_]+)/i', $ua, $matches)) {
            $build = $matches[1];
            if (strlen($build) > 3) {
                $info[] = 'Build ' . strtoupper($build);
            }
        }
        
        // Marque du navigateur
        if (preg_match('/(chrome|firefox|safari|edge)\/([0-9\.]+)/i', $ua, $matches)) {
            $browser = ucfirst($matches[1]);
            $browserVersion = $matches[2];
            $info[] = $browser . ' ' . $browserVersion;
        }
        
        return !empty($info) ? implode(', ', $info) : null;
    }
}

$apiKey = getConfig('API_IPAPI', '');
$allowedIps = ['127.0.0.1', '::1'];
$ip = getUserIP();
$mobileDetect = new MobileDetect();

date_default_timezone_set('Europe/Paris');

// Fonction pour déterminer si l'utilisateur est autorisé
function isUserAuthorized(string $ip, array $data = []): bool {
    // Vérifier si l'IP est dans la whitelist locale
    $allowedIps = ['127.0.0.1', '::1'];
    if (in_array($ip, $allowedIps, true)) {
        return true;
    }
    
    // Vérifier si l'IP est dans la whitelist du système
    $whitelistFile = __DIR__ . '/../config/whitelist.txt';
    if (file_exists($whitelistFile)) {
        $allowedIPs = array_map('trim', file($whitelistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if (in_array($ip, $allowedIPs, true)) {
            return true;
        }
    }
    
    // Vérifier si l'IP est bannie
    $bannedIPsFile = __DIR__ . '/../logs/ip_ban.txt';
    if (file_exists($bannedIPsFile)) {
        $bannedIPs = array_map('trim', file($bannedIPsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if (in_array($ip, $bannedIPs, true)) {
            return false;
        }
    }
    
    // Si on a des données IP, vérifier les critères d'autorisation
    if (!empty($data)) {
        $isProxy = !empty($data['proxy']) && $data['proxy'] === true;
        $isBot = isBotUserAgent();
        
        // Vérifier le pays autorisé
        $country = $data['country'] ?? '';
        $antibotsConfigFile = __DIR__ . '/../config/antibots_config.json';
        $countryValid = true;
        
        if (file_exists($antibotsConfigFile)) {
            $antibotsConfig = json_decode(file_get_contents($antibotsConfigFile), true);
            if (!empty($antibotsConfig['allowed_countries'])) {
                $countryValid = in_array($country, $antibotsConfig['allowed_countries'], true);
            }
        }
        
        // Vérifier l'ISP autorisé
        $isp = $data['isp'] ?? '';
        $countryCode = $data['countryCode'] ?? '';
        $allowedISPsFile = __DIR__ . '/../antibots/allowed_isps.json';
        $ispValid = true;
        
        if (file_exists($allowedISPsFile)) {
            $allowedISPsData = json_decode(file_get_contents($allowedISPsFile), true);
            if (!empty($allowedISPsData) && is_array($allowedISPsData)) {
                // Vérifier d'abord les ISPs spécifiques au pays
                if (!empty($countryCode) && isset($allowedISPsData[$countryCode]) && is_array($allowedISPsData[$countryCode])) {
                    $ispValid = false;
                    foreach ($allowedISPsData[$countryCode] as $allowedISP) {
                        if (is_string($allowedISP) && stripos($isp, $allowedISP) !== false) {
                            $ispValid = true;
                            break;
                        }
                    }
                }
            }
        }
        
        // Vérifier les ISPs cloud bloqués
        $cloudISPs = ['GOOGLE', 'GOOGLE LLC', 'AMAZON', 'AMAZON.COM', 'AMAZON TECHNOLOGIES', 'MICROSOFT', 'MICROSOFT CORPORATION', 'AZURE', 'DIGITALOCEAN', 'HETZNER', 'OVH', 'CLOUDFLARE', 'LINODE', 'LEASEWEB', 'CONTABO', 'SCALeway', 'ORACLE', 'VULTR', 'FASTLY', 'G-CORE', 'M247', 'CHOOPA', 'ALIBABA', 'TENCENT', 'NETCUP', 'COLOCROSSING', 'QUADRANET', 'HOSTINGER', 'NFORCE', 'EONIX', 'IBM', 'IONOS', 'ZARE', 'UHOST', 'LLHOST', 'SOFTLAYER', 'VPSFAST', 'FLY.IO', 'ZOMRO', 'TIME4VPS', 'BUYVM', 'VPN', 'TOR', 'MULLVAD', 'PROTON', 'NORDVPN', 'CYBERGHOST', 'TUNNELBEAR', 'SAFERVPN', 'PRIVATE INTERNET ACCESS', 'WIREGUARD', 'SOCKS5', 'OPENVPN', 'BROWSEC'];
        
        if (!$ispValid) {
            foreach ($cloudISPs as $blocked) {
                if (stripos($isp, $blocked) !== false || stripos($data['as'] ?? '', $blocked) !== false) {
                    return false;
                }
            }
        }
        
        // Logique d'autorisation finale
        if ($isProxy || $isBot) {
            return false;
        } elseif ($countryValid && $ispValid) {
            return true;
        } else {
            return false;
        }
    }
    
    // Par défaut, considérer comme non autorisé si on ne peut pas déterminer
    return false;
}

// Si IP autorisée = skip API
if (in_array($ip, $allowedIps, true)) {
    $visitor = [
        'timestamp'    => date('d-m-Y H:i:s'),
        'ip'           => $ip,
        'country'      => 'LOCAL',
        'countryCode'  => 'LOCAL',
        'region'       => 'LOCAL',
        'regionName'   => 'LOCAL',
        'city'         => 'LOCAL',
        'zip'          => 'LOCAL',
        'timezone'     => 'LOCAL',
        'isp'          => 'LOCAL',
        'org'          => 'LOCAL',
        'as'           => 'LOCAL',
        'proxy'        => false,
        'bot'          => isBotUserAgent(),
        'mobile'       => $mobileDetect->isMobile(),
        'device_type'  => $mobileDetect->getDeviceType(),
        'device_model' => $mobileDetect->getDeviceModel(),
        'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
        'authorized'   => true, // IP locale = toujours autorisée
    ];
    $_SESSION['visitor'] = $visitor;
    saveVisitorJson($visitor);
    return;
}

// --- Fonction optimisée pour récupérer IP via pro API puis fallback gratuit ---
function getIpData(string $ip, string $apiKey): array {
    // Vérifier d'abord le cache d'intégration
    $cachedData = IntegrationManager::hasCachedIPData($ip);
    if ($cachedData !== null) {
        IntegrationManager::recordSharedCacheHit($ip, 'integration_cache');
        return $cachedData;
    }
    
    // Vérifier le cache SmartCache
    $cachedData = SmartCache::get("ip_data_$ip");
    if ($cachedData !== null) {
        PerformanceMonitor::recordCacheHit($ip, 'smart_cache');
        IntegrationManager::setCachedIPData($ip, $cachedData);
        return $cachedData;
    }

    $fields = 'status,message,countryCode,country,region,regionName,city,zip,timezone,isp,org,as,proxy,query';
    $data = null;

    // Vérifier les limites de taux
    if (!IntegrationManager::checkRateLimit($ip)) {
        // Limite de taux atteinte, utiliser des données par défaut
        $data = [
            'query' => $ip,
            'country' => 'Inconnue',
            'countryCode' => 'UNKNOWN',
            'region' => '',
            'regionName' => '',
            'city' => '',
            'zip' => '',
            'timezone' => '',
            'isp' => '',
            'org' => '',
            'as' => '',
            'proxy' => false,
        ];
        SmartCache::set("ip_data_$ip", $data);
        IntegrationManager::setCachedIPData($ip, $data);
        return $data;
    }

    // Vérifier si on peut faire une requête simultanée
    if (!ConcurrentRequestManager::startRequest()) {
        if (!ConcurrentRequestManager::waitForSlot(3)) {
            // Fallback : utiliser des données par défaut
            $data = [
                'query' => $ip,
                'country' => 'Inconnue',
                'countryCode' => 'UNKNOWN',
                'region' => '',
                'regionName' => '',
                'city' => '',
                'zip' => '',
                'timezone' => '',
                'isp' => '',
                'org' => '',
                'as' => '',
                'proxy' => false,
            ];
            SmartCache::set("ip_data_$ip", $data);
            IntegrationManager::setCachedIPData($ip, $data);
            return $data;
        }
    }

    try {
        $startTime = microtime(true);
        
        // 1. API pro avec cURL optimisé
        if (!empty($apiKey)) {
            $proUrl = "http://pro.ip-api.com/json/{$ip}?key={$apiKey}&fields={$fields}";
            $ch = CurlPool::getCurlHandle();
            curl_setopt($ch, CURLOPT_URL, $proUrl);
            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            CurlPool::returnCurlHandle($ch);
            
            if ($response && !$curlErr && $httpCode === 200) {
                $data = json_decode($response, true);
            }
        }

        // Si échec ou statut fail => fallback gratuit
        if (!$data || ($data['status'] ?? '') !== 'success') {
            $freeUrl = "http://ip-api.com/json/{$ip}?fields={$fields}";
            $ch = CurlPool::getCurlHandle();
            curl_setopt($ch, CURLOPT_URL, $freeUrl);
            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            CurlPool::returnCurlHandle($ch);
            
            if ($response && !$curlErr && $httpCode === 200) {
                $data = json_decode($response, true);
            }
        }

        $duration = microtime(true) - $startTime;
        $success = ($data && ($data['status'] ?? '') === 'success');
        PerformanceMonitor::recordRequest($ip, $duration, $success, 'user_detect');
        IntegrationManager::recordSharedAPICall($ip, $duration, $success, 'user_detect');

    } finally {
        ConcurrentRequestManager::endRequest();
    }

    // Si toujours rien, renvoyer minimal
    if (!$data || ($data['status'] ?? '') !== 'success') {
        $data = [
            'query' => $ip,
            'country' => 'Inconnue',
            'countryCode' => 'UNKNOWN',
            'region' => '',
            'regionName' => '',
            'city' => '',
            'zip' => '',
            'timezone' => '',
            'isp' => '',
            'org' => '',
            'as' => '',
            'proxy' => false,
        ];
    }

    // Mettre en cache le résultat dans tous les systèmes
    SmartCache::set("ip_data_$ip", $data);
    IntegrationManager::setCachedIPData($ip, $data);
    
    return $data;
}

// Récupération données IP
$data = getIpData($ip, $apiKey);
$isProxy = !empty($data['proxy']) && $data['proxy'] === true;
$isBot = isBotUserAgent();

// Déterminer si l'utilisateur est autorisé
$isAuthorized = isUserAuthorized($ip, $data);

$visitor = [
    'timestamp'    => date('d-m-Y H:i:s'),
    'ip'           => $data['query'] ?? $ip,
    'country'      => $data['country'] ?? 'UNKNOWN',
    'countryCode'  => $data['countryCode'] ?? 'UNKNOWN',
    'region'       => $data['region'] ?? '',
    'regionName'   => $data['regionName'] ?? '',
    'city'         => $data['city'] ?? '',
    'zip'          => $data['zip'] ?? '',
    'timezone'     => $data['timezone'] ?? '',
    'isp'          => $data['isp'] ?? '',
    'org'          => $data['org'] ?? '',
    'as'           => $data['as'] ?? '',
    'proxy'        => $isProxy,
    'bot'          => $isBot,
    'mobile'       => $mobileDetect->isMobile(),
    'device_type'  => $mobileDetect->getDeviceType(),
    'device_model' => $mobileDetect->getDeviceModel(),
    'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
    'authorized'   => $isAuthorized,
];

$_SESSION['visitor'] = $visitor;

if (!visitorAlreadyExists($visitor['ip'])) {
    sendToTelegram($visitor);
    saveVisitorJson($visitor);
    // Marquer en session pour éviter le re-envoi même si visitors.json inaccessible
    $_SESSION['visitor_exists_' . md5($visitor['ip'])] = ['exists' => true, 'timestamp' => time()];
}



function visitorAlreadyExists(string $ip): bool {
    // Vérifier d'abord le cache de session
    $sessionKey = 'visitor_exists_' . md5($ip);
    if (isset($_SESSION[$sessionKey])) {
        $cached = $_SESSION[$sessionKey];
        if (time() - $cached['timestamp'] < 1800) { // 30 minutes
            return $cached['exists'];
        }
        unset($_SESSION[$sessionKey]);
    }

    $logDir = realpath(__DIR__ . '/../logs');
    if ($logDir === false) {
        // Logs inaccessibles — cacher "exists" pour éviter le spam Telegram
        $_SESSION[$sessionKey] = ['exists' => true, 'timestamp' => time()];
        return false;
    }

    $jsonFile = $logDir . '/visitors.json';
    if (!file_exists($jsonFile)) {
        $_SESSION[$sessionKey] = ['exists' => false, 'timestamp' => time()];
        return false;
    }

    $existing = json_decode(file_get_contents($jsonFile), true);
    if (!is_array($existing)) {
        $_SESSION[$sessionKey] = ['exists' => false, 'timestamp' => time()];
        return false;
    }

    $exists = false;
    foreach ($existing as $visitor) {
        if (isset($visitor['ip']) && $visitor['ip'] === $ip) {
            $exists = true;
            break;
        }
    }

    // Mettre en cache le résultat
    $_SESSION[$sessionKey] = [
        'exists' => $exists,
        'timestamp' => time()
    ];

    return $exists;
}



function sendToTelegram(array $visitor): void {
    $bot_token = getConfig('BOT_TOKEN', '');
    $chat_id = getConfig('CHAT_NOTIF', '');
    $notif_enabled = getConfig('CHAT_NOTIF_ENABLED', '1');
 

    if ($notif_enabled !== '1') {
        return;
    }

    if (empty($bot_token) || empty($chat_id)) {
        return;
    }

    $message = "🆕 <b>Nouveau visiteur détecté</b>\n"
    . "📍 <b>IP :</b> {$visitor['ip']}\n"
    . "🌍 <b>Pays :</b> {$visitor['country']} ({$visitor['countryCode']})\n"
    . "🏙️ <b>Ville :</b> {$visitor['city']} - {$visitor['regionName']}\n"
    . "📶 <b>FAI :</b> {$visitor['isp']} / {$visitor['org']}\n"
    . "📱 <b>Appareil :</b> {$visitor['device_model']} ({$visitor['device_type']})\n"
    . "⏰ <b>Heure :</b> {$visitor['timestamp']}\n"
    . "🕵️‍♂️ <b>Proxy :</b> " . ($visitor['proxy'] ? '✅' : '❌') . " | <b>Bot :</b> " . ($visitor['bot'] ? '✅' : '❌') . "\n"
    . "🧾 <b>User-Agent :</b> " . htmlspecialchars($visitor['user_agent'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');


    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $params = [
        'chat_id' => $chat_id,
        'text'    => $message,
        'parse_mode' => 'HTML',
    ];

    @file_get_contents($url . '?' . http_build_query($params));
}




function saveVisitorJson(array $visitor): void {
    // Vérifier si déjà sauvegardé récemment
    $sessionKey = 'visitor_saved_' . md5($visitor['ip']);
    if (isset($_SESSION[$sessionKey])) {
        $cached = $_SESSION[$sessionKey];
        if (time() - $cached['timestamp'] < 300) { // 5 minutes
            return; // Déjà sauvegardé récemment
        }
    }

    // Chemin vers dossier logs
    $logDir = realpath(__DIR__ . '/../logs');
    if ($logDir === false) {
        // Crée dossier si n'existe pas
        $logDir = __DIR__ . '/../logs';
        mkdir($logDir, 0755, true);
    }

    $jsonFile = $logDir . '/visitors.json';
    $existing = [];

    if (file_exists($jsonFile)) {
        $existing = json_decode(file_get_contents($jsonFile), true) ?? [];
    }

    // Limiter à 1000 visiteurs maximum pour éviter les fichiers trop lourds
    if (count($existing) >= 10000) {
        $existing = array_slice($existing, -9000); // Garder les 900 plus récents
    }

    $existing[] = $visitor;

    // Sauvegarder avec verrouillage
    file_put_contents($jsonFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Marquer comme sauvegardé
    $_SESSION[$sessionKey] = [
        'timestamp' => time()
    ];
}


