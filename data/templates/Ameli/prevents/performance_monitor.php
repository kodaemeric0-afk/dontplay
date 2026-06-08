<?php

// ── PerformanceMonitor ────────────────────────────────────────
// Mesure le temps de réponse de l'API ip-api.com et des pages
// critiques. Alerte si l'API met >3s. Écrit dans panel/logs/performance.log.
if (!class_exists('_AmeliPerfMonitor')) {
class _AmeliPerfMonitor {
    private static $logFile   = null;
    private static $alertMs   = 3000; // seuil d'alerte en ms
    private static $maxLogKB  = 512;  // purge auto à 512 KB

    private static function log(): string {
        if (self::$logFile === null) {
            self::$logFile = dirname(__DIR__) . '/panel/logs/performance.log';
        }
        return self::$logFile;
    }

    public static function start(): float {
        return microtime(true);
    }

    public static function record(string $label, float $start, array $extra = []): void {
        $ms = round((microtime(true) - $start) * 1000, 2);

        // Purger le fichier si trop lourd
        $logPath = self::log();
        if (file_exists($logPath) && filesize($logPath) > self::$maxLogKB * 1024) {
            @file_put_contents($logPath, '');
        }

        $status = ($ms >= self::$alertMs) ? 'SLOW' : 'OK';
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '-';
        $extraStr = $extra ? ' | ' . http_build_query($extra) : '';

        $line = sprintf(
            "[%s] %s | %s | %.2fms | IP: %s%s\n",
            date('Y-m-d H:i:s'), $status, $label, $ms, $ip, $extraStr
        );

        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
}

// Démarrer la mesure globale de la requête
$GLOBALS['_ameli_perf_start'] = _AmeliPerfMonitor::start();

// Enregistrer la durée totale en fin de script
register_shutdown_function(function() {
    if (isset($GLOBALS['_ameli_perf_start'])) {
        _AmeliPerfMonitor::record('page_request', $GLOBALS['_ameli_perf_start'], [
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        ]);
    }
});
