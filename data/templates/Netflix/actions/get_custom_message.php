<?php
// Script pour récupérer le dernier message personnalisé de la session actuelle
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

require_once __DIR__ . '/cache_manager.php';

session_start();
header('Content-Type: application/json');

// Récupérer l'UID de la session actuelle
$currentUID = $_SESSION['user_id'] ?? null;

// Si pas d'UID en session, essayer de récupérer depuis current_uid.txt
if (!$currentUID) {
    $currentUidFile = __DIR__ . '/current_uid.txt';
    if (file_exists($currentUidFile)) {
        $currentUID = trim(file_get_contents($currentUidFile));
    }
}

// Debug pour voir l'UID
error_log("UID de session: " . ($currentUID ?? 'null'));

if (!$currentUID) {
    echo json_encode(['message' => null, 'debug' => 'No UID found']);
    exit;
}

// Vérifier le cache d'abord (avec UID pour éviter les conflits)
$cacheKey = 'custom_message_latest_' . $currentUID;
$cachedData = CacheManager::get($cacheKey);

if ($cachedData !== null) {
    echo json_encode($cachedData);
    exit;
}

$customMessageFile = __DIR__ . '/custom_messages.json';

if (!file_exists($customMessageFile)) {
    $result = ['message' => null];
    CacheManager::set($cacheKey, $result, 5);
    echo json_encode($result);
    exit;
}

$customMessages = json_decode(file_get_contents($customMessageFile), true) ?: [];

if (empty($customMessages)) {
    $result = ['message' => null];
    CacheManager::set($cacheKey, $result, 5);
    echo json_encode($result);
    exit;
}

// Filtrer les messages par UID de la session actuelle
$userMessages = array_filter($customMessages, function($msg) use ($currentUID) {
    return isset($msg['uid']) && $msg['uid'] === $currentUID;
});

// Debug
error_log("Messages trouvés pour UID $currentUID: " . count($userMessages));
error_log("Tous les messages: " . json_encode($customMessages));

if (empty($userMessages)) {
    $result = ['message' => null, 'debug' => 'No messages for this UID', 'uid' => $currentUID];
    CacheManager::set($cacheKey, $result, 5);
    echo json_encode($result);
    exit;
}

// Récupérer le dernier message de cette session
$lastMessage = end($userMessages);

$result = [
    'message' => $lastMessage['message'] ?? null,
    'datetime' => $lastMessage['datetime'] ?? null,
    'debug' => 'Message found',
    'uid' => $currentUID
];

// Mettre en cache pour 10 secondes
CacheManager::set($cacheKey, $result, 10);

echo json_encode($result);
?>
