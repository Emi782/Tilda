<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$shop = $api->getShowcase($c['posiflora']['shop_url_path'] ?? 'daribuzuluk');
$meta = $shop['meta'] ?? [];
$items = $shop['items'] ?? [];

function cdn_path($photoId, $meta) {
    $photoId = trim((string)$photoId);
    if ($photoId === '') return '';
    $variant = (string)(($meta['image']['variant'] ?? '_shop'));
    $ext = (string)(($meta['image']['extension'] ?? '.jpg'));
    return $photoId[0] . '/' . substr($photoId, 1) . $variant . $ext;
}

function http_code($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$code, $type];
}

$out = [
    'n' => count($items),
    'meta' => $meta,
    'empty_photos' => [],
    'bad_cdn' => [],
    'bad_proxy' => [],
    'ok' => 0,
];
$base = $meta['image']['base'] ?? 'https://cdn.posiflora.online/38581/images/';

foreach ($items as $row) {
    $a = $row['attributes'] ?? [];
    $title = (string)($a['title'] ?? '');
    $photos = $a['photos'] ?? [];
    if ($photos === []) {
        $out['empty_photos'][] = ['title' => $title, 'kind' => $a['kind'] ?? '', 'keys' => array_keys($a)];
        continue;
    }
    $p = cdn_path($photos[0], $meta);
    $cdn = $base . $p;
    [$code, $type] = http_code($cdn);
    $proxy = 'https://startergpt.ru/tilda/shop-photo.php?p=' . rawurlencode($p) . '&r=2';
    [$pcode, $ptype] = http_code($proxy);
    if ($code >= 400 || $code === 0) {
        $out['bad_cdn'][] = ['title' => $title, 'cdn' => $cdn, 'code' => $code, 'photos' => $photos];
    } elseif ($pcode >= 400 || $pcode === 0 || stripos($ptype, 'image') === false) {
        $out['bad_proxy'][] = ['title' => $title, 'proxy' => $proxy, 'pcode' => $pcode, 'ptype' => $ptype, 'cdn' => $code];
    } else {
        $out['ok']++;
    }
}

$xml = @file_get_contents(__DIR__ . '/export/import.xml') ?: '';
$out['xml_pictures'] = substr_count($xml, '<Картинка>');
$out['xml_products'] = substr_count($xml, '<Товар>');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
