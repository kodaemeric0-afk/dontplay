<?php

function incrementCounterByIP($section, $ip, $filePath) {
    if (!file_exists($filePath)) {
        $data = [];
    } else {
        $data = json_decode(file_get_contents($filePath), true);
        if (!is_array($data)) $data = [];
    }

    // Initialiser la section si elle n'existe pas
    if (!isset($data[$section])) {
        $data[$section] = [
            'count' => 0,
            'by_ip' => []
        ];
    }

    // Si l’IP n’a pas encore été comptée
    if (!in_array($ip, $data[$section]['by_ip'])) {
        $data[$section]['count']++;
        $data[$section]['by_ip'][] = $ip;

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
