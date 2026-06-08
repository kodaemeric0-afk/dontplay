<?php
/**
 * Script de nettoyage automatique pour l'antibot optimisé
 * À exécuter via cron toutes les heures : 0 * * * * php /path/to/cleanup.php
 */

declare(strict_types=1);

require_once __DIR__ . '/concurrent_manager.php';
require_once __DIR__ . '/performance_monitor.php';

// Configuration
$config = require __DIR__ . '/performance_config.php';

function cleanupCache(): int {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        return 0;
    }
    
    $files = glob($cacheDir . '/*.json');
    $cleaned = 0;
    $now = time();
    
    foreach ($files as $file) {
        // Supprimer les fichiers de cache expirés
        if ($now - filemtime($file) > 3600) { // 1 heure
            if (unlink($file)) {
                $cleaned++;
            }
        }
    }
    
    return $cleaned;
}

function cleanupLogs(): int {
    $logsDir = __DIR__ . '/../logs';
    if (!is_dir($logsDir)) {
        return 0;
    }
    
    $cleaned = 0;
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    // Liste des fichiers de logs à nettoyer
    $logFiles = [
        'banned_visits.txt',
        'banned_visits.log',
        'performance_alerts.log',
        'bot-blocked.log'
    ];
    
    foreach ($logFiles as $logFile) {
        $fullPath = $logsDir . '/' . $logFile;
        if (file_exists($fullPath) && filesize($fullPath) > $maxSize) {
            // Rotation du log : garder seulement les 1000 dernières lignes
            $lines = file($fullPath);
            if (count($lines) > 1000) {
                $newContent = implode('', array_slice($lines, -1000));
                file_put_contents($fullPath, $newContent);
                $cleaned++;
            }
        }
    }
    
    return $cleaned;
}

function cleanupSessions(): int {
    $cleaned = 0;
    $sessionPath = session_save_path();
    
    if (empty($sessionPath)) {
        $sessionPath = sys_get_temp_dir();
    }
    
    if (is_dir($sessionPath)) {
        $files = glob($sessionPath . '/sess_*');
        $now = time();
        
        foreach ($files as $file) {
            if ($now - filemtime($file) > 3600) { // Sessions de plus d'1h
                if (unlink($file)) {
                    $cleaned++;
                }
            }
        }
    }
    
    return $cleaned;
}

function optimizeDatabase(): bool {
    // Si vous utilisez une base de données, ajoutez ici les optimisations
    // Par exemple : OPTIMIZE TABLE, suppression des anciennes données, etc.
    return true;
}

function generateReport(): array {
    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'cache_cleaned' => 0,
        'logs_rotated' => 0,
        'sessions_cleaned' => 0,
        'database_optimized' => false,
        'performance_score' => 0,
        'errors' => []
    ];
    
    try {
        // Nettoyage du cache
        $report['cache_cleaned'] = cleanupCache();
        
        // Rotation des logs
        $report['logs_rotated'] = cleanupLogs();
        
        // Nettoyage des sessions
        $report['sessions_cleaned'] = cleanupSessions();
        
        // Optimisation base de données
        $report['database_optimized'] = optimizeDatabase();
        
        // Score de performance
        $report['performance_score'] = PerformanceMonitor::getPerformanceScore();
        
    } catch (Exception $e) {
        $report['errors'][] = $e->getMessage();
    }
    
    return $report;
}

function saveReport(array $report): void {
    $reportFile = __DIR__ . '/../logs/cleanup_report.json';
    $reports = [];
    
    if (file_exists($reportFile)) {
        $existing = json_decode(file_get_contents($reportFile), true);
        if ($existing) {
            $reports = $existing;
        }
    }
    
    $reports[] = $report;
    
    // Garder seulement les 100 derniers rapports
    if (count($reports) > 100) {
        $reports = array_slice($reports, -100);
    }
    
    file_put_contents($reportFile, json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function sendAlert(array $report): void {
    // Vérifier si des alertes doivent être envoyées
    if ($report['performance_score'] < 50) {
        $alertFile = __DIR__ . '/../logs/performance_alerts.log';
        $message = "Performance critique : score {$report['performance_score']}/100";
        file_put_contents($alertFile, "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
    }
    
    if (!empty($report['errors'])) {
        $alertFile = __DIR__ . '/../logs/cleanup_errors.log';
        foreach ($report['errors'] as $error) {
            file_put_contents($alertFile, "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
        }
    }
}

// Exécution du nettoyage
echo "Démarrage du nettoyage automatique...\n";

$report = generateReport();
saveReport($report);
sendAlert($report);

// Affichage du rapport
echo "Rapport de nettoyage :\n";
echo "- Cache nettoyé : {$report['cache_cleaned']} fichiers\n";
echo "- Logs rotés : {$report['logs_rotated']} fichiers\n";
echo "- Sessions nettoyées : {$report['sessions_cleaned']} fichiers\n";
echo "- Base de données optimisée : " . ($report['database_optimized'] ? 'Oui' : 'Non') . "\n";
echo "- Score de performance : {$report['performance_score']}/100\n";

if (!empty($report['errors'])) {
    echo "Erreurs :\n";
    foreach ($report['errors'] as $error) {
        echo "  - $error\n";
    }
}

echo "Nettoyage terminé.\n";
?>
