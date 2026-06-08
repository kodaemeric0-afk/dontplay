<?php

http_response_code(404);

function includeAllAntiFiles($baseDir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($file->isFile() && preg_match('#/prevents/(anti(\d+)\.php)$#', $path, $m)) {
            $files[(int)$m[2]] = $file->getPathname();
        }
    }
    ksort($files, SORT_NUMERIC);
    foreach ($files as $filePath) {
        include_once $filePath;
    }
}

includeAllAntiFiles(dirname(__DIR__));