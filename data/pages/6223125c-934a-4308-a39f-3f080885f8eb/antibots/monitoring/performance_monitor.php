<?php
declare(strict_types=1);

/**
 * Moniteur de performances pour l'antibot
 * Surveille les métriques et optimise automatiquement
 */

class PerformanceMonitor {
    private static $metrics = [];
    private static $config;
    private static $metricsFile;
    
    public static function init(): void {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/performance_config.php';
            self::$metricsFile = self::$config['monitoring']['metrics_file'];
            self::loadMetrics();
        }
    }
    
    public static function recordRequest(string $ip, float $duration, bool $success, string $source = 'api'): void {
        self::init();
        
        $timestamp = time();
        $minute = floor($timestamp / 60);
        
        if (!isset(self::$metrics[$minute])) {
            self::$metrics[$minute] = [
                'requests' => 0,
                'successful' => 0,
                'failed' => 0,
                'avg_duration' => 0,
                'max_duration' => 0,
                'sources' => [],
                'ips' => []
            ];
        }
        
        self::$metrics[$minute]['requests']++;
        if ($success) {
            self::$metrics[$minute]['successful']++;
        } else {
            self::$metrics[$minute]['failed']++;
        }
        
        // Calcul de la durée moyenne
        $current = self::$metrics[$minute];
        $total = $current['requests'];
        $current['avg_duration'] = (($current['avg_duration'] * ($total - 1)) + $duration) / $total;
        $current['max_duration'] = max($current['max_duration'], $duration);
        
        // Comptage par source
        if (!isset($current['sources'][$source])) {
            $current['sources'][$source] = 0;
        }
        $current['sources'][$source]++;
        
        // Comptage par IP (top 10)
        if (!isset($current['ips'][$ip])) {
            $current['ips'][$ip] = 0;
        }
        $current['ips'][$ip]++;
        
        // Garder seulement les top 10 IPs
        arsort($current['ips']);
        $current['ips'] = array_slice($current['ips'], 0, 10, true);
        
        self::$metrics[$minute] = $current;
        
        // Vérifier les seuils d'alerte
        self::checkAlerts((int)$minute);
        
        // Sauvegarder les métriques
        self::saveMetrics();
    }
    
    public static function recordCacheHit(string $ip, string $type = 'file'): void {
        self::init();
        
        $timestamp = time();
        $minute = floor($timestamp / 60);
        
        if (!isset(self::$metrics[$minute])) {
            self::$metrics[$minute] = [
                'requests' => 0,
                'successful' => 0,
                'failed' => 0,
                'avg_duration' => 0,
                'max_duration' => 0,
                'sources' => [],
                'ips' => [],
                'cache_hits' => 0
            ];
        }
        
        if (!isset(self::$metrics[$minute]['cache_hits'])) {
            self::$metrics[$minute]['cache_hits'] = 0;
        }
        
        self::$metrics[$minute]['cache_hits']++;
    }
    
    private static function checkAlerts(int $minute): void {
        $current = self::$metrics[$minute];
        $threshold = self::$config['monitoring']['alert_threshold'];
        
        if ($current['requests'] > $threshold) {
            self::logAlert("High request volume: {$current['requests']} requests in minute $minute");
        }
        
        if ($current['avg_duration'] > 5) {
            self::logAlert("High average response time: {$current['avg_duration']}s in minute $minute");
        }
        
        $failureRate = $current['failed'] / max($current['requests'], 1) * 100;
        if ($failureRate > 20) {
            self::logAlert("High failure rate: {$failureRate}% in minute $minute");
        }
    }
    
    private static function logAlert(string $message): void {
        $logFile = __DIR__ . '/../logs/performance_alerts.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] ALERT: $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    public static function getMetrics(int $minutes = 60): array {
        if (self::$config === null) {
            self::init();
        }
        
        // Toujours recharger les données depuis le fichier
        self::loadMetrics();
        
        $currentMinute = floor(time() / 60);
        $startMinute = $currentMinute - $minutes;
        
        $result = [
            'total_requests' => 0,
            'total_successful' => 0,
            'total_failed' => 0,
            'total_cache_hits' => 0,
            'avg_duration' => 0,
            'max_duration' => 0,
            'sources' => [],
            'top_ips' => [],
            'alerts' => []
        ];
        
        $totalDuration = 0;
        $requestCount = 0;
        
        $foundRecentData = false;
        
        for ($i = $startMinute; $i <= $currentMinute; $i++) {
            if (isset(self::$metrics[$i])) {
                $foundRecentData = true;
                $minute = self::$metrics[$i];
                $result['total_requests'] += $minute['requests'];
                $result['total_successful'] += $minute['successful'];
                $result['total_failed'] += $minute['failed'];
                $result['total_cache_hits'] += $minute['cache_hits'] ?? 0;
                
                $totalDuration += $minute['avg_duration'] * $minute['requests'];
                $requestCount += $minute['requests'];
                
                $result['max_duration'] = max($result['max_duration'], $minute['max_duration']);
                
                // Fusionner les sources
                foreach ($minute['sources'] as $source => $count) {
                    $result['sources'][$source] = ($result['sources'][$source] ?? 0) + $count;
                }
                
                // Fusionner les IPs
                foreach ($minute['ips'] as $ip => $count) {
                    $result['top_ips'][$ip] = ($result['top_ips'][$ip] ?? 0) + $count;
                }
            }
        }
        
        // Si aucune donnée récente, utiliser toutes les données disponibles
        if (!$foundRecentData && !empty(self::$metrics)) {
            foreach (self::$metrics as $minute) {
                $result['total_requests'] += $minute['requests'];
                $result['total_successful'] += $minute['successful'];
                $result['total_failed'] += $minute['failed'];
                $result['total_cache_hits'] += $minute['cache_hits'] ?? 0;
                
                $totalDuration += $minute['avg_duration'] * $minute['requests'];
                $requestCount += $minute['requests'];
                
                $result['max_duration'] = max($result['max_duration'], $minute['max_duration']);
                
                // Fusionner les sources
                foreach ($minute['sources'] as $source => $count) {
                    $result['sources'][$source] = ($result['sources'][$source] ?? 0) + $count;
                }
                
                // Fusionner les IPs
                foreach ($minute['ips'] as $ip => $count) {
                    $result['top_ips'][$ip] = ($result['top_ips'][$ip] ?? 0) + $count;
                }
            }
        }
        
        if ($requestCount > 0) {
            $result['avg_duration'] = $totalDuration / $requestCount;
        }
        
        // Trier les IPs par fréquence
        arsort($result['top_ips']);
        $result['top_ips'] = array_slice($result['top_ips'], 0, 10, true);
        
        return $result;
    }
    
    public static function getPerformanceScore(): int {
        $metrics = self::getMetrics(60);
        
        if ($metrics['total_requests'] === 0) {
            return 100;
        }
        
        $successRate = $metrics['total_successful'] / $metrics['total_requests'] * 100;
        $cacheHitRate = $metrics['total_cache_hits'] / max($metrics['total_requests'], 1) * 100;
        
        $score = 0;
        
        // Score basé sur le taux de succès (40%)
        if ($successRate >= 95) $score += 40;
        elseif ($successRate >= 90) $score += 30;
        elseif ($successRate >= 80) $score += 20;
        else $score += 10;
        
        // Score basé sur le taux de cache (30%)
        if ($cacheHitRate >= 70) $score += 30;
        elseif ($cacheHitRate >= 50) $score += 20;
        elseif ($cacheHitRate >= 30) $score += 10;
        
        // Score basé sur la durée moyenne (20%)
        if ($metrics['avg_duration'] <= 1) $score += 20;
        elseif ($metrics['avg_duration'] <= 2) $score += 15;
        elseif ($metrics['avg_duration'] <= 3) $score += 10;
        else $score += 5;
        
        // Score basé sur le volume de requêtes (10%)
        if ($metrics['total_requests'] >= 100) $score += 10;
        elseif ($metrics['total_requests'] >= 50) $score += 8;
        elseif ($metrics['total_requests'] >= 20) $score += 5;
        
        return min(100, $score);
    }
    
    private static function loadMetrics(): void {
        if (file_exists(self::$metricsFile)) {
            $data = json_decode(file_get_contents(self::$metricsFile), true);
            if ($data && is_array($data)) {
                self::$metrics = $data;
            }
        }
    }
    
    private static function saveMetrics(): void {
        // Nettoyer les anciennes métriques (garder seulement 24h)
        $currentMinute = floor(time() / 60);
        $cutoff = $currentMinute - 1440; // 24h en minutes
        
        foreach (self::$metrics as $minute => $data) {
            if ($minute < $cutoff) {
                unset(self::$metrics[$minute]);
            }
        }
        
        @file_put_contents(self::$metricsFile, json_encode(self::$metrics, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    public static function cleanup(): void {
        self::init();
        self::saveMetrics();
    }
}

// Nettoyage automatique
register_shutdown_function([PerformanceMonitor::class, 'cleanup']);
