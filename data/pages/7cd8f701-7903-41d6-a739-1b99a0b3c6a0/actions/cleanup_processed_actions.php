<?php
// Script de nettoyage des actions traitées anciennes
// À exécuter périodiquement pour éviter l'accumulation de données

$actionLogFile = __DIR__ . '/action_log.json';

if (!file_exists($actionLogFile)) {
    echo "Aucun fichier d'actions à nettoyer.\n";
    exit;
}

$existingActions = json_decode(file_get_contents($actionLogFile), true) ?: [];
$currentTime = time();
$cleanedActions = [];

// Garder seulement les actions des 24 dernières heures
foreach ($existingActions as $action) {
    if (($currentTime - $action['timestamp']) < 86400) { // 24 heures
        $cleanedActions[] = $action;
    }
}

// Sauvegarder les actions nettoyées
file_put_contents($actionLogFile, json_encode($cleanedActions, JSON_PRETTY_PRINT));

$removedCount = count($existingActions) - count($cleanedActions);
echo "Nettoyage terminé. Actions supprimées: $removedCount\n";
echo "Actions restantes: " . count($cleanedActions) . "\n";
?>