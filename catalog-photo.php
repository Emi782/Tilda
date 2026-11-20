<?php
/**
 * Публичная картинка для Тильды: Tilda качает HTTPS без авторизации Posiflora.
 */
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/PosifloraClient.php';
$config = require __DIR__ . '/config.php';

$id = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    exit;
}

try {
    $api = new PosifloraClient($config['posiflora']);
    $img = $api->getImage($id);
    $attrs = $img['data']['attributes'] ?? [];
    $url = '';
    foreach (['fileShop', 'fileMedium', 'file', 'url', 'original', 'path', 'src'] as $key) {
        if (!empty($attrs[$key]) && is_string($attrs[$key])) {
            $url = $attrs[$key];
            break;
        }
    }
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        $url = rtrim($api->getBaseUrl(), '/') . '/v1/images/' . $id;
    }
    $bin = $api->downloadBinary($url);
    $type = $bin['content_type'] ?: 'image/jpeg';
    if (stripos($type, 'json') !== false || stripos($type, 'html') !== false) {
        $type = 'image/jpeg';
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    echo $bin['body'];
} catch (Throwable $e) {
    http_response_code(404);
}
