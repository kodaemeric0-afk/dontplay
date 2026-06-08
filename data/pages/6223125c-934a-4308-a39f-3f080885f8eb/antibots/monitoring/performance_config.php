<?php
/**
 * Configuration des performances pour l'antibot optimisé
 */

return [
    // Configuration du cache
    'cache' => [
        'ttl' => 300, // 5 minutes
        'session_ttl' => 1800, // 30 minutes
        'max_files' => 1000, // Nombre max de fichiers de cache
        'cleanup_interval' => 3600, // Nettoyage toutes les heures
    ],
    
    // Configuration des requêtes simultanées
    'concurrent' => [
        'max_requests' => 1000, // Max requêtes simultanées
        'wait_timeout' => 5, // Timeout d'attente en secondes
        'retry_attempts' => 3, // Nombre de tentatives
    ],
    
    // Configuration cURL
    'curl' => [
        'timeout' => 3, // Timeout de connexion
        'max_timeout' => 4, // Timeout maximum
        'pool_size' => 10, // Taille du pool de connexions
        'user_agent' => 'AntiBotScript/1.0',
    ],
    
    // Configuration de l'API IP
    'api' => [
        'pro_url' => 'http://pro.ip-api.com/json/%s?key=%s&fields=status,message,countryCode,proxy,query,isp,mobile,hosting,as',
        'free_url' => 'http://ip-api.com/json/%s?fields=status,message,countryCode,proxy,query,isp,mobile,hosting,as',
        'rate_limit' => 1000, // Limite de requêtes par minute
        'burst_limit' => 50, // Limite de rafales
    ],
    
    // Configuration des logs
    'logging' => [
        'enabled' => true,
        'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
        'max_file_size' => 10485760, // 10MB
        'rotation' => 'daily',
    ],
    
    // Configuration de la session
    'session' => [
        'cache_prefix' => 'antibot_',
        'encrypt' => false, // Chiffrement des données de session
        'lifetime' => 1800, // Durée de vie de la session
    ],
    
    // Configuration de la base de données (si utilisée)
    'database' => [
        'enabled' => false,
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'antibot',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    
    // Configuration Redis (si disponible)
    'redis' => [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
        'timeout' => 2.5,
    ],
    
    // Configuration de monitoring
    'monitoring' => [
        'enabled' => true,
        'metrics_file' => __DIR__ . '/../../logs/metrics.json',
        'alert_threshold' => 100, // Seuil d'alerte pour les requêtes/min
    ],
];
