<?php

if (session_status() == PHP_SESSION_NONE) session_start();

// Skip if already whitelisted
if (isset($_SESSION['bot']) && $_SESSION['bot'] === false) return;

if (!function_exists('_ab6_get_ip')) {
    function _ab6_get_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ── Classe ProgressiveBan ─────────────────────────────────────
if (!class_exists('_AmeliProgressiveBan')) {
class _AmeliProgressiveBan {
    private static $banFile      = null;
    private static $attemptsFile = null;
    private static $logFile      = null;
    private static $maxAttempts  = 5;
    private static $windowSec    = 3600;  // 1h
    private static $cleanupAge   = 86400; // 24h

    private static function init() {
        $base = __DIR__;
        self::$banFile      = $base . '/banned_ips.txt';
        self::$attemptsFile = $base . '/ban_attempts.json';
        self::$logFile      = dirname($base) . '/panel/logs/progressive_bans.log';
    }

    private static function load(): array {
        self::init();
        if (!file_exists(self::$attemptsFile)) return [];
        $raw = @file_get_contents(self::$attemptsFile);
        $data = $raw ? @json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private static function save(array $data): void {
        $now = time();
        // Purger les IPs inactives depuis >24h
        foreach ($data as $ip => $entry) {
            if (($now - ($entry['last_attempt'] ?? 0)) > self::$cleanupAge) {
                unset($data[$ip]);
            }
        }
        @file_put_contents(self::$attemptsFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public static function isBanned(string $ip): bool {
        self::init();
        if (!file_exists(self::$banFile)) return false;
        $lines = @file(self::$banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return is_array($lines) && in_array(trim($ip), array_map('trim', $lines));
    }

    public static function record(string $ip, string $reason): bool {
        $data = self::load();
        $now  = time();

        if (!isset($data[$ip])) {
            $data[$ip] = ['count' => 0, 'first_attempt' => $now, 'last_attempt' => $now, 'reasons' => []];
        }

        // Garder uniquement les tentatives dans la fenêtre
        $data[$ip]['reasons'] = array_filter(
            $data[$ip]['reasons'],
            fn($r) => ($now - $r['ts']) < self::$windowSec
        );

        $data[$ip]['count']++;
        $data[$ip]['last_attempt'] = $now;
        $data[$ip]['reasons'][] = ['reason' => $reason, 'ts' => $now];

        // Limiter historique à 20 entrées
        if (count($data[$ip]['reasons']) > 20) {
            $data[$ip]['reasons'] = array_slice($data[$ip]['reasons'], -20);
        }

        self::save($data);

        // Bannir si seuil atteint dans la fenêtre
        $recentCount = count($data[$ip]['reasons']);
        if ($recentCount >= self::$maxAttempts) {
            self::ban($ip, $data[$ip]);
            return true; // bannissement déclenché
        }

        return false;
    }

    private static function ban(string $ip, array $entry): void {
        if (self::isBanned($ip)) return;

        @file_put_contents(self::$banFile, $ip . PHP_EOL, FILE_APPEND | LOCK_EX);

        $reasons = implode(', ', array_column(array_slice($entry['reasons'], -5), 'reason'));
        $log = sprintf(
            "[%s] PROGRESSIVE BAN | IP: %s | Attempts: %d | Reasons: %s\n",
            date('Y-m-d H:i:s'), $ip, $entry['count'], $reasons
        );
        @file_put_contents(self::$logFile, $log, FILE_APPEND | LOCK_EX);
    }
}
}

$_ab6_ip = _ab6_get_ip();

// Check if already permanently banned
if (_AmeliProgressiveBan::isBanned($_ab6_ip)) {
    $_SESSION['bot'] = true;
    $_SESSION['bot_reason'] = 'progressive_ban:permanently_banned';
    return;
}

// If another anti file already flagged this request as a bot, record the attempt
if (!empty($_SESSION['bot']) && !empty($_SESSION['bot_reason'])) {
    _AmeliProgressiveBan::record($_ab6_ip, $_SESSION['bot_reason']);
}
