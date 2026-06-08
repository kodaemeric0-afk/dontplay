<?php
declare(strict_types=1);

/**
 * Gestionnaire d'intégration entre l'antibot et user_detect
 * Évite les appels API dupliqués et optimise les performances
 */

class IntegrationManager {
    private static $config;
    private static $initialized = false;
    
    public static function init(): void {
        if (self::$initialized) return;
        
        self::$config = require __DIR__ . '/integration_config.php';
        self::$initialized = true;
    }
    
    /**
     * Vérifie si les données IP sont déjà disponibles dans la session
     */
    public static function hasCachedIPData(string $ip): ?array {
        self::init();
        
        if (!self::$config['integration']['shared_cache']) {
            return null;
        }
        
        $sessionKey = 'shared_ip_data_' . md5($ip);
        if (isset($_SESSION[$sessionKey])) {
            $cached = $_SESSION[$sessionKey];
            if (time() - $cached['timestamp'] < self::$config['shared_cache']['ip_data_ttl']) {
                return $cached['data'];
            }
            unset($_SESSION[$sessionKey]);
        }
        
        return null;
    }
    
    /**
     * Met en cache les données IP partagées
     */
    public static function setCachedIPData(string $ip, array $data): void {
        self::init();
        
        if (!self::$config['integration']['shared_cache']) {
            return;
        }
        
        $sessionKey = 'shared_ip_data_' . md5($ip);
        $_SESSION[$sessionKey] = [
            'data' => $data,
            'timestamp' => time()
        ];
    }
    
    /**
     * Vérifie si un visiteur existe déjà
     */
    public static function hasCachedVisitor(string $ip): ?array {
        self::init();
        
        if (!self::$config['integration']['shared_cache']) {
            return null;
        }
        
        $sessionKey = 'shared_visitor_' . md5($ip);
        if (isset($_SESSION[$sessionKey])) {
            $cached = $_SESSION[$sessionKey];
            if (time() - $cached['timestamp'] < self::$config['shared_cache']['visitor_ttl']) {
                return $cached['data'];
            }
            unset($_SESSION[$sessionKey]);
        }
        
        return null;
    }
    
    /**
     * Met en cache les données de visiteur partagées
     */
    public static function setCachedVisitor(string $ip, array $visitor): void {
        self::init();
        
        if (!self::$config['integration']['shared_cache']) {
            return;
        }
        
        $sessionKey = 'shared_visitor_' . md5($ip);
        $_SESSION[$sessionKey] = [
            'data' => $visitor,
            'timestamp' => time()
        ];
    }
    
    /**
     * Vérifie si les données de device sont déjà disponibles
     */
    public static function hasCachedDeviceData(): ?array {
        self::init();
        
        if (!self::$config['integration']['shared_cache']) {
            return null;
        }
        
        $sessionKey = 'shared_device_data';
        if (isset($_SESSION[$sessionKey])) {
            $cached = $_SESSION[$sessionKey];
            if (time() - $cached['timestamp'] < self::$config['shared_cache']['device_ttl']) {
                return $cached['data'];
            }
            unset($_SESSION[$sessionKey]);
        }
        
        return null;
    }
    
    /**
     * Met en cache les données de device partagées
     */
    public static function setCachedDeviceData(array $deviceData): void {
        self::init();
        
        if (!self::$config['integration']['shared_cache']) {
            return;
        }
        
        $sessionKey = 'shared_device_data';
        $_SESSION[$sessionKey] = [
            'data' => $deviceData,
            'timestamp' => time()
        ];
    }
    
    /**
     * Détermine quel système doit s'exécuter en premier
     */
    public static function getExecutionOrder(): array {
        self::init();
        
        $priority = self::$config['priority']['priority_system'];
        
        switch ($priority) {
            case 'antibot':
                return ['antibot', 'user_detect'];
            case 'user_detect':
                return ['user_detect', 'antibot'];
            case 'balanced':
                // Exécution en parallèle si possible
                return ['parallel'];
            default:
                return ['antibot', 'user_detect'];
        }
    }
    
    /**
     * Vérifie si un appel API est nécessaire
     */
    public static function needsAPICall(string $ip): bool {
        self::init();
        
        // Vérifier le cache partagé
        if (self::hasCachedIPData($ip)) {
            return false;
        }
        
        // Vérifier le cache de session
        $sessionKey = 'antibot_' . md5($ip);
        if (isset($_SESSION[$sessionKey])) {
            $cached = $_SESSION[$sessionKey];
            if (time() - $cached['timestamp'] < self::$config['shared_cache']['session_ttl']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Enregistre un appel API partagé
     */
    public static function recordSharedAPICall(string $ip, float $duration, bool $success, string $source): void {
        self::init();
        
        if (!self::$config['logging']['unified_logs']) {
            return;
        }
        
        // Utiliser le PerformanceMonitor si disponible
        if (class_exists('PerformanceMonitor')) {
            PerformanceMonitor::recordRequest($ip, $duration, $success, $source);
        }
        
        // Log unifié
        $logFile = __DIR__ . '/../../logs/unified_api_calls.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] IP: $ip | Source: $source | Duration: {$duration}s | Success: " . ($success ? 'Yes' : 'No') . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Enregistre un hit de cache partagé
     */
    public static function recordSharedCacheHit(string $ip, string $type): void {
        self::init();
        
        if (!self::$config['logging']['cache_hit_tracking']) {
            return;
        }
        
        // Utiliser le PerformanceMonitor si disponible
        if (class_exists('PerformanceMonitor')) {
            PerformanceMonitor::recordCacheHit($ip, $type);
        }
        
        // Log unifié
        $logFile = __DIR__ . '/../../logs/unified_cache_hits.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] IP: $ip | Type: $type | Cache Hit\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Vérifie les limites de taux
     */
    public static function checkRateLimit(string $ip): bool {
        self::init();
        
        if (!self::$config['api_calls']['max_per_minute']) {
            return true;
        }
        
        $sessionKey = 'rate_limit_' . md5($ip);
        $now = time();
        $minute = floor($now / 60);
        
        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [
                'minute' => $minute,
                'count' => 0
            ];
        }
        
        $rateData = $_SESSION[$sessionKey];
        
        // Reset si nouvelle minute
        if ($rateData['minute'] !== $minute) {
            $_SESSION[$sessionKey] = [
                'minute' => $minute,
                'count' => 0
            ];
            $rateData = $_SESSION[$sessionKey];
        }
        
        // Vérifier la limite
        if ($rateData['count'] >= self::$config['api_calls']['max_per_minute']) {
            return false;
        }
        
        // Incrémenter le compteur
        $_SESSION[$sessionKey]['count']++;
        
        return true;
    }
    
    /**
     * Nettoie les caches expirés
     */
    public static function cleanupExpiredCaches(): int {
        self::init();
        
        $cleaned = 0;
        $now = time();
        
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, 'shared_') === 0 || strpos($key, 'antibot_') === 0) {
                if (is_array($value) && isset($value['timestamp'])) {
                    $ttl = self::$config['shared_cache']['session_ttl'];
                    if ($now - $value['timestamp'] > $ttl) {
                        unset($_SESSION[$key]);
                        $cleaned++;
                    }
                }
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Obtient les métriques unifiées
     */
    public static function getUnifiedMetrics(): array {
        self::init();
        
        $metrics = [
            'total_requests' => 0,
            'cache_hits' => 0,
            'api_calls' => 0,
            'errors' => 0,
            'performance_score' => 0
        ];
        
        // Utiliser le PerformanceMonitor si disponible
        if (class_exists('PerformanceMonitor')) {
            $perfMetrics = PerformanceMonitor::getMetrics(60);
            $metrics = array_merge($metrics, $perfMetrics);
        }
        
        return $metrics;
    }
    
    /**
     * Vérifie si l'intégration est activée
     */
    public static function isEnabled(): bool {
        self::init();
        return self::$config['integration']['enabled'];
    }
    
    /**
     * Obtient la configuration
     */
    public static function getConfig(): array {
        self::init();
        return self::$config;
    }
}

// Nettoyage automatique des caches expirés
register_shutdown_function(function() {
    if (class_exists('IntegrationManager')) {
        IntegrationManager::cleanupExpiredCaches();
    }
});
