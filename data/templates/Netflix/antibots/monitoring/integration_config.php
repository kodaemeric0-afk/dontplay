<?php
/**
 * Configuration d'intégration entre l'antibot et user_detect
 * Évite les appels API dupliqués et optimise les performances
 */

return [
    // Configuration de l'intégration
    'integration' => [
        'enabled' => true,
        'shared_cache' => true,
        'unified_session' => true,
        'priority_system' => 'antibot', // antibot, user_detect, or balanced
    ],
    
    // Configuration des caches partagés
    'shared_cache' => [
        'ip_data_ttl' => 300, // 5 minutes
        'visitor_ttl' => 1800, // 30 minutes
        'device_ttl' => 3600, // 1 heure
        'session_ttl' => 1800, // 30 minutes
    ],
    
    // Configuration des priorités
    'priority' => [
        'antibot_first' => true, // L'antibot vérifie en premier
        'user_detect_after' => true, // user_detect s'exécute après
        'skip_duplicate_checks' => true, // Éviter les vérifications dupliquées
    ],
    
    // Configuration des appels API
    'api_calls' => [
        'max_per_minute' => 100,
        'max_concurrent' => 50,
        'timeout' => 3,
        'retry_attempts' => 2,
        'fallback_enabled' => true,
    ],
    
    // Configuration des logs
    'logging' => [
        'unified_logs' => true,
        'performance_tracking' => true,
        'error_tracking' => true,
        'cache_hit_tracking' => true,
    ],
    
    // Configuration des sessions
    'session' => [
        'unified_keys' => true,
        'encryption' => false,
        'compression' => false,
        'lifetime' => 1800,
    ],
    
    // Configuration des notifications
    'notifications' => [
        'unified_telegram' => true,
        'deduplicate_alerts' => true,
        'performance_alerts' => true,
        'error_alerts' => true,
    ],
    
    // Configuration de la base de données (si utilisée)
    'database' => [
        'enabled' => false,
        'unified_tables' => true,
        'indexing' => true,
        'cleanup_interval' => 3600,
    ],
    
    // Configuration Redis (si disponible)
    'redis' => [
        'enabled' => false,
        'shared_keyspace' => 'antibot:',
        'compression' => true,
        'serialization' => 'json',
    ],
    
    // Configuration de monitoring
    'monitoring' => [
        'unified_metrics' => true,
        'real_time_tracking' => true,
        'performance_scoring' => true,
        'alert_thresholds' => [
            'response_time' => 5.0,
            'error_rate' => 0.1,
            'cache_hit_rate' => 0.7,
            'concurrent_requests' => 80,
        ],
    ],
    
    // Configuration de sécurité
    'security' => [
        'rate_limiting' => true,
        'ip_whitelist' => true,
        'bot_detection' => true,
        'proxy_detection' => true,
        'geo_blocking' => true,
    ],
    
    // Configuration des performances
    'performance' => [
        'opcache_enabled' => true,
        'memory_limit' => '256M',
        'max_execution_time' => 30,
        'connection_pooling' => true,
        'lazy_loading' => true,
    ],
];
