<?php
// Script pour nettoyer les anciens messages personnalisés
if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

$customMessageFile = __DIR__ . '/custom_messages.json';

if (!file_exists($customMessageFile)) {
    exit;
}

$customMessages = json_decode(file_get_contents($customMessageFile), true) ?: [];

// Supprimer les messages de plus de 24 heures
$cutoffTime = time() - (24 * 60 * 60); // 24 heures
$filteredMessages = array_filter($customMessages, function($msg) use ($cutoffTime) {
    return isset($msg['timestamp']) && $msg['timestamp'] > $cutoffTime;
});

// Réindexer le tableau
$filteredMessages = array_values($filteredMessages);

// Sauvegarder seulement les messages récents
file_put_contents($customMessageFile, json_encode($filteredMessages, JSON_PRETTY_PRINT));

echo "Nettoyage terminé. " . (count($customMessages) - count($filteredMessages)) . " messages anciens supprimés.";
?>
