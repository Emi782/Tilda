<?php
header('Access-Control-Allow-Origin: https://store.tilda.ru');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/xml; charset=utf-8');
$path = __DIR__ . '/catalog.yml';
if (!is_file($path)) {
    http_response_code(404);
    echo 'missing';
    exit;
}
readfile($path);
