<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$id = $argv[1] ?? '3f25531e-ad74-4645-a471-e6e307479c43';
$r = $api->get('/v1/orders/' . $id, ['include' => 'customer,store,source']);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
$recent = $api->get('/v1/orders', [
    'page[size]' => 8,
    'sort' => '-createdAt',
]);
echo "RECENT:\n";
foreach ($recent['data'] ?? [] as $row) {
    $a = $row['attributes'] ?? [];
    echo ($a['docNo'] ?? '') . ' | ' . ($a['status'] ?? '') . ' | ' . ($a['createdAt'] ?? $a['date'] ?? '') . ' | ' . ($a['deliveryContact'] ?? '') . ' | ' . ($row['id'] ?? '') . "\n";
}
