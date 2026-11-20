<?php
/**
 * Прокси фото витрины: Тильда качает HTTPS с нашего домена,
 * CDN Posiflora с чужих IP часто не отдаёт картинку в импорт.
 * Если варианта _shop нет, берём соседний файл того же снимка.
 */
header('Access-Control-Allow-Origin: *');

$p = (string)($_GET['p'] ?? '');
if (!preg_match('#^[a-z0-9][a-z0-9_\\-./]*\\.(jpg|jpeg|png|webp)$#i', $p) || strpos($p, '..') !== false) {
    http_response_code(400);
    exit;
}

$cacheDir = __DIR__ . '/export/photo-cache';
$cacheKey = hash('sha256', $p);
$cacheFile = $cacheDir . '/' . $cacheKey . '.bin';
$cacheType = $cacheDir . '/' . $cacheKey . '.type';
if (is_file($cacheFile) && filesize($cacheFile) > 50) {
    $type = is_file($cacheType) ? trim((string)file_get_contents($cacheType)) : 'image/jpeg';
    header('Content-Type: ' . ($type !== '' ? $type : 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

$candidates = [$p];
if (preg_match('#^(.*?)(?:_(?:shop|medium|preview|orig))?(\\.[a-z0-9]+)$#i', $p, $m)) {
    $stem = $m[1];
    foreach (['_shop.jpg', '.jpg', '_medium.jpg', '_shop.jpeg', '_shop.png', '_shop.webp', '.jpeg', '.png', '.webp'] as $suffix) {
        $candidates[] = $stem . $suffix;
    }
}
$candidates = array_values(array_unique($candidates));

foreach ($candidates as $rel) {
    if (!preg_match('#^[a-z0-9][a-z0-9_\\-./]*\\.(jpg|jpeg|png|webp)$#i', $rel) || strpos($rel, '..') !== false) {
        continue;
    }
    $url = 'https://cdn.posiflora.online/38581/images/' . $rel;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; DariCatalog/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $code >= 400 || strlen($body) < 50) {
        continue;
    }
    if ($type === '' || stripos($type, 'json') !== false || stripos($type, 'html') !== false) {
        $type = 'image/jpeg';
    }
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $body);
        @file_put_contents($cacheType, $type);
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    echo $body;
    exit;
}

http_response_code(404);
