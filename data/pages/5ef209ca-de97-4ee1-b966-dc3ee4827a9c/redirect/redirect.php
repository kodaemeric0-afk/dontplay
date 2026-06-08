<?php
if (!isset($_GET['ip'])) {
    http_response_code(400);
    echo "Missing parameters";
    exit;
}

$ip = $_GET['ip'];

if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    echo "Invalid IP";
    exit;
}

$dataFile = __DIR__ . '/redirects.txt';

if (!file_exists($dataFile)) {
    http_response_code(500);
    echo "Data file missing";
    exit;
}

$lines = file($dataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$updatedLines = [];
$found = false;

foreach ($lines as $line) {
    $parts = preg_split('/\s*-\s*/', $line);
    if (count($parts) === 3 && $parts[0] === $ip) {
        $found = true;
        continue;
    }
    $updatedLines[] = implode(' - ', $parts);
}

if ($found) {
    file_put_contents($dataFile, implode(PHP_EOL, $updatedLines) . PHP_EOL);
    echo "Line removed successfully";
} else {
    http_response_code(404);
    echo "IP not found";
}
