<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$cats = $api->getAllCategories();
$all = $api->getAllInventoryItems(['public' => null]);
$specs = $api->getAllSpecifications(['public' => null]);

$catTitle = [];
$catPublic = [];
foreach ($cats as $cat) {
    $id = $cat['id'] ?? '';
    $catTitle[$id] = $cat['attributes']['title'] ?? '';
    $catPublic[$id] = $cat['attributes']['countPublicItems'] ?? null;
}

$want = [];
foreach ($all as $it) {
    $a = $it['attributes'] ?? [];
    $title = (string)($a['title'] ?? '');
    $cid = $it['relationships']['category']['data']['id'] ?? '';
    $ct = $catTitle[$cid] ?? '';
    if (preg_match('/букет|композиц|коробк|коробка|сборн/iu', $title . ' ' . $ct)) {
        $want[] = [
            'title' => $title,
            'cat' => $ct,
            'public' => $a['public'] ?? null,
            'price' => $a['priceMin'] ?? $a['minPrice'] ?? null,
        ];
    }
}

$specList = [];
foreach ($specs as $s) {
    $a = $s['attributes'] ?? [];
    $cid = $s['relationships']['category']['data']['id'] ?? '';
    $specList[] = [
        'title' => $a['title'] ?? '',
        'public' => $a['public'] ?? null,
        'minPrice' => $a['minPrice'] ?? null,
        'cat' => $catTitle[$cid] ?? '',
        'keys' => array_keys($a),
    ];
}

$catOut = [];
foreach ($cats as $cat) {
    $t = $cat['attributes']['title'] ?? '';
    if (preg_match('/букет|роз|цвет|эквадор|компози/iu', $t)) {
        $catOut[] = [
            'title' => $t,
            'public_count' => $cat['attributes']['countPublicItems'] ?? null,
            'parent' => $catTitle[$cat['relationships']['parent']['data']['id'] ?? ''] ?? null,
        ];
    }
}

echo json_encode([
    'bouquet_like_inventory' => ['count' => count($want), 'sample' => array_slice($want, 0, 20)],
    'specs' => $specList,
    'flower_cats' => $catOut,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
