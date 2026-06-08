<?php
declare(strict_types=1);

/**
 * Système de bannissement progressif pour l'antibot
 * Bannit automatiquement les IPs qui tentent de contourner le système
 */

class ProgressiveBan {
    private static $banFile = __DIR__ . '/../logs/ip_ban.txt';
    private static $attemptsFile = __DIR__ . '/../logs/ban_attempts.json';
    private static $maxAttempts = 100; // Nombre max de tentatives avant bannissement (désactivé pratiquement)
    private static $banDuration = 3600; // Durée du bannissement en secondes (1h)
    
    /**
     * Enregistre une tentative de contournement
     */
    public static function recordAttempt(string $ip, string $reason): void {
        $attempts = self::loadAttempts();
        $now = time();
        
        if (!isset($attempts[$ip])) {
            $attempts[$ip] = [
                'count' => 0,
                'first_attempt' => $now,
                'last_attempt' => $now,
                'reasons' => []
            ];
        }
        
        $attempts[$ip]['count']++;
        $attempts[$ip]['last_attempt'] = $now;
        $attempts[$ip]['reasons'][] = [
            'reason' => $reason,
            'timestamp' => $now
        ];
        
        // Garder seulement les 10 dernières raisons
        if (count($attempts[$ip]['reasons']) > 10) {
            $attempts[$ip]['reasons'] = array_slice($attempts[$ip]['reasons'], -10);
        }
        
        self::saveAttempts($attempts);
        
        // Vérifier si l'IP doit être bannie
        if ($attempts[$ip]['count'] >= self::$maxAttempts) {
            self::banIP($ip, $attempts[$ip]);
        }
    }
    
    /**
     * Bannit une IP
     */
    private static function banIP(string $ip, array $attemptData): void {
        // Ajouter l'IP à la liste des bannies
        if (!self::isIPBanned($ip)) {
            file_put_contents(self::$banFile, $ip . PHP_EOL, FILE_APPEND | LOCK_EX);
            
            // Mettre à jour le compteur de robots bannis
            require_once __DIR__ . '/../func/update_banned_counters.php';
            $reason = 'Bannissement progressif (' . $attemptData['count'] . ' tentatives)';
            updateBannedRobotsCounter($ip, $reason);
            
            // Log du bannissement
            $logEntry = sprintf(
                "[%s] PROGRESSIVE BAN | IP: %s | Attempts: %d | Reasons: %s\n",
                date('Y-m-d H:i:s'),
                $ip,
                $attemptData['count'],
                implode(', ', array_column($attemptData['reasons'], 'reason'))
            );
            
            file_put_contents(__DIR__ . '/../logs/progressive_bans.log', $logEntry, FILE_APPEND | LOCK_EX);
            
            // Nettoyer les tentatives pour cette IP
            $attempts = self::loadAttempts();
            unset($attempts[$ip]);
            self::saveAttempts($attempts);
        }
    }
    
    /**
     * Vérifie si une IP est bannie
     */
    public static function isIPBanned(string $ip): bool {
        if (!file_exists(self::$banFile)) {
            return false;
        }
        
        $bannedIPs = file(self::$banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return in_array($ip, $bannedIPs, true);
    }
    
    /**
     * Charge les tentatives depuis le fichier
     */
    private static function loadAttempts(): array {
        if (!file_exists(self::$attemptsFile)) {
            return [];
        }
        
        $data = json_decode(file_get_contents(self::$attemptsFile), true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Sauvegarde les tentatives dans le fichier
     */
    private static function saveAttempts(array $attempts): void {
        // Nettoyer les anciennes tentatives (plus de 24h)
        $now = time();
        foreach ($attempts as $ip => $data) {
            if ($now - $data['last_attempt'] > 86400) { // 24h
                unset($attempts[$ip]);
            }
        }
        
        file_put_contents(self::$attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    /**
     * Obtient les statistiques de bannissement
     */
    public static function getStats(): array {
        $attempts = self::loadAttempts();
        $bannedIPs = 0;
        
        if (file_exists(self::$banFile)) {
            $bannedList = file(self::$banFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $bannedIPs = is_array($bannedList) ? count($bannedList) : 0;
        }
        
        $topAttempters = [];
        if (is_array($attempts)) {
            foreach ($attempts as $ip => $data) {
                $topAttempters[] = ['ip' => $ip, 'count' => $data['count']];
            }
            // Trier par nombre de tentatives
            usort($topAttempters, function($a, $b) {
                return $b['count'] - $a['count'];
            });
            $topAttempters = array_slice($topAttempters, 0, 10);
        }
        
        return [
            'total_attempts' => is_array($attempts) ? count($attempts) : 0,
            'banned_ips' => $bannedIPs,
            'top_attempters' => $topAttempters
        ];
    }
    
    /**
     * Nettoie les anciens bannissements
     */
    public static function cleanup(): void {
        // Cette fonction peut être appelée périodiquement pour nettoyer les anciens bannissements
        // Pour l'instant, on garde tous les bannissements
    }
}

// Nettoyage automatique
register_shutdown_function([ProgressiveBan::class, 'cleanup']);
