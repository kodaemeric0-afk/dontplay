<?php
declare(strict_types=1);

/**
 * Gestionnaire de requêtes simultanées pour l'antibot
 * Évite la surcharge de l'API IP avec trop de requêtes simultanées
 */

class ConcurrentRequestManager {
    private static $activeRequests = 0;
    private static $maxConcurrent = 50;
    private static $lockFile;
    
    public static function init(): void {
        self::$lockFile = sys_get_temp_dir() . '/antibot_concurrent.lock';
    }
    
    public static function canMakeRequest(): bool {
        self::init();
        
        // Vérifier le nombre de requêtes actives
        if (self::$activeRequests >= self::$maxConcurrent) {
            return false;
        }
        
        // Vérifier le fichier de verrouillage
        if (file_exists(self::$lockFile)) {
            $lockData = json_decode(file_get_contents(self::$lockFile), true);
            if ($lockData && (time() - $lockData['timestamp']) < 60) {
                if ($lockData['count'] >= self::$maxConcurrent) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    public static function startRequest(): bool {
        if (!self::canMakeRequest()) {
            return false;
        }
        
        self::$activeRequests++;
        
        // Mettre à jour le fichier de verrouillage
        $lockData = [
            'count' => self::$activeRequests,
            'timestamp' => time()
        ];
        file_put_contents(self::$lockFile, json_encode($lockData), LOCK_EX);
        
        return true;
    }
    
    public static function endRequest(): void {
        if (self::$activeRequests > 0) {
            self::$activeRequests--;
        }
        
        // Mettre à jour le fichier de verrouillage
        $lockData = [
            'count' => self::$activeRequests,
            'timestamp' => time()
        ];
        file_put_contents(self::$lockFile, json_encode($lockData), LOCK_EX);
    }
    
    public static function waitForSlot(int $maxWait = 5): bool {
        $waited = 0;
        while (!self::canMakeRequest() && $waited < $maxWait) {
            usleep(100000); // Attendre 100ms
            $waited += 0.1;
        }
        return self::canMakeRequest();
    }
}

/**
 * Pool de connexions cURL pour optimiser les requêtes simultanées
 */
class CurlPool {
    private static $pool = [];
    private static $maxPoolSize = 10;
    
    public static function getCurlHandle(): \CurlHandle {
        if (!empty(self::$pool)) {
            return array_pop(self::$pool);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'AntiBotScript/1.0',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ]);
        
        return $ch;
    }
    
    public static function returnCurlHandle(\CurlHandle $ch): void {
        if (count(self::$pool) < self::$maxPoolSize) {
            curl_reset($ch);
            // Réappliquer les options par défaut après reset (curl_reset efface tout)
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_USERAGENT => 'AntiBotScript/1.0',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
            ]);
            self::$pool[] = $ch;
        } else {
            curl_close($ch);
        }
    }
    
    public static function cleanup(): void {
        foreach (self::$pool as $ch) {
            curl_close($ch);
        }
        self::$pool = [];
    }
}

/**
 * Cache intelligent avec compression et rotation
 */
class SmartCache {
    private static $cacheDir;
    private static $maxCacheSize = 1000; // Nombre max de fichiers de cache
    
    public static function init(): void {
        self::$cacheDir = __DIR__ . '/../cache';
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    public static function get(string $key): ?array {
        self::init();
        $file = self::$cacheDir . '/' . md5($key) . '.json';
        
        if (file_exists($file) && (time() - filemtime($file) < 300)) {
            $data = json_decode(file_get_contents($file), true);
            return $data ?: null;
        }
        
        return null;
    }
    
    public static function set(string $key, array $data): void {
        self::init();
        $file = self::$cacheDir . '/' . md5($key) . '.json';
        
        // Rotation du cache si nécessaire
        self::rotateCacheIfNeeded();
        
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    
    private static function rotateCacheIfNeeded(): void {
        $files = glob(self::$cacheDir . '/*.json');
        if (count($files) > self::$maxCacheSize) {
            // Supprimer les fichiers les plus anciens
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $toDelete = array_slice($files, 0, count($files) - self::$maxCacheSize);
            foreach ($toDelete as $file) {
                @unlink($file);
            }
        }
    }
    
    public static function cleanup(): void {
        self::init();
        $files = glob(self::$cacheDir . '/*.json');
        $now = time();
        
        foreach ($files as $file) {
            if ($now - filemtime($file) > 3600) { // Supprimer les caches de plus d'1h
                @unlink($file);
            }
        }
    }
}

// Nettoyage automatique à la fin du script
register_shutdown_function(function() {
    CurlPool::cleanup();
    SmartCache::cleanup();
});
