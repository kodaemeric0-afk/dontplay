<?php
$called_cards_file = '../data/called.json';

function get_called_cards($file) {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['called']) || !is_array($data['called'])) return [];
    return $data['called'];
}

function save_called_cards($file, $called) {
    $data = ['called' => array_values($called)];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $called = get_called_cards($called_cards_file);
    header('Content-Type: application/json');
    echo json_encode(['called' => array_values($called)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['cardKey']) || !isset($input['called'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing cardKey or called']);
        exit;
    }
    $cardKey = $input['cardKey'];
    $called = (bool)$input['called'];

    $fp = fopen($called_cards_file, 'c+');
    if ($fp === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not open data file']);
        exit;
    }
    flock($fp, LOCK_EX);

    $file_size = filesize($called_cards_file);
    $json = $file_size > 0 ? fread($fp, $file_size) : '';
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['called']) || !is_array($data['called'])) {
        $data = ['called' => []];
    }
    $called_cards = $data['called'];

    if ($called) {
        if (!in_array($cardKey, $called_cards)) {
            $called_cards[] = $cardKey;
        }
    } else {
        $called_cards = array_values(array_diff($called_cards, [$cardKey]));
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['called' => array_values($called_cards)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'called' => array_values($called_cards)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
