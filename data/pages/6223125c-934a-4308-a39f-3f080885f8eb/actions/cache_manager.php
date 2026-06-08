<?php
// Gestionnaire de cache simple pour accélérer les réponses

if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

class CacheManager {
    private static $cacheDir = __DIR__ . '/cache/';
    private static $defaultTTL = 30; // 30 secondes par défaut
    
    public static function init() {
        if (!file_exists(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    public static function get($key) {
        $file = self::$cacheDir . md5($key) . '.cache';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['expires'] > time()) {
                return $data['data'];
            }
            unlink($file); // Supprimer le cache expiré
        }
        return null;
    }
    
    public static function set($key, $data, $ttl = null) {
        self::init();
        $file = self::$cacheDir . md5($key) . '.cache';
        $ttl = $ttl ?? self::$defaultTTL;
        $cacheData = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
        file_put_contents($file, json_encode($cacheData));
    }
    
    public static function clear($key = null) {
        if ($key) {
            $file = self::$cacheDir . md5($key) . '.cache';
            if (file_exists($file)) {
                unlink($file);
            }
        } else {
            // Vider tout le cache
            $files = glob(self::$cacheDir . '*.cache');
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
?>
