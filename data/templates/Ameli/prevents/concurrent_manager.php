<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

// ── ConcurrentRequestManager ──────────────────────────────────
// Limite le nombre de requêtes simultanées par IP pour éviter
// le crawling agressif et protéger ip-api.com contre le flooding.
if (!class_exists('_AmeliConcurrentManager')) {
class _AmeliConcurrentManager {
    private static $maxConcurrent = 50;
    private static $lockTtl       = 10;  // secondes avant expiration d'un lock

    private static function lockFile(string $ip): string {
        $safe = preg_replace('/[^a-zA-Z0-9_.]/', '_', $ip);
        return sys_get_temp_dir() . '/ameli_fw_' . $safe . '.lock';
    }

    public static function acquire(string $ip): bool {
        // Compter les locks actifs totaux
        $lockPattern = sys_get_temp_dir() . '/ameli_fw_*.lock';
        $active = 0;
        foreach (glob($lockPattern) ?: [] as $f) {
            if ((time() - @filemtime($f)) < self::$lockTtl) {
                $active++;
            } else {
                @unlink($f); // purger les locks expirés
            }
        }

        if ($active >= self::$maxConcurrent) {
            return false;
        }

        @file_put_contents(self::lockFile($ip), getmypid(), LOCK_EX);
        return true;
    }

    public static function release(string $ip): void {
        @unlink(self::lockFile($ip));
    }
}
}

$_cmgr_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!_AmeliConcurrentManager::acquire($_cmgr_ip)) {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'concurrent_limit:too_many_simultaneous_requests';
    // Libérer quand même le lock si jamais il a été posé
    _AmeliConcurrentManager::release($_cmgr_ip);
    return;
}

// Enregistrer la libération du lock en fin de script
register_shutdown_function(function() use ($_cmgr_ip) {
    _AmeliConcurrentManager::release($_cmgr_ip);
});
