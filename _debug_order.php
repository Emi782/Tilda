<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$api->login();

$uuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);
$payload = [
    'data' => [
        'type' => 'orders',
        'id' => $uuid,
        'attributes' => [
            'status' => 'new',
            'date' => date('Y-m-d'),
            'docNo' => 'T' . date('ymdHi') . '8',
            'description' => 'debug order',
            'delivery' => false,
            'deliveryContact' => 'Test',
            'deliveryPhoneCode' => '7',
            'deliveryPhoneNumber' => '9097132773',
        ],
        'relationships' => [
            'store' => ['data' => ['type' => 'stores', 'id' => $c['posiflora']['store_id']]],
            'source' => ['data' => ['type' => 'order-sources', 'id' => $c['posiflora']['source_id']]],
        ],
    ],
];

$itemId = $argv[1] ?? '';
if ($itemId !== '') {
    $payload['data']['relationships']['lines'] = [
        'data' => [[
            'type' => 'order-lines',
            'attributes' => [
                'qty' => '1',
                'price' => '100',
                'totalAmount' => '100',
                'manualSetting' => true,
            ],
            'relationships' => [
                'item' => ['data' => ['type' => 'inventory-items', 'id' => $itemId]],
            ],
        ]],
    ];
}

$ch = curl_init($api->getBaseUrl() . '/v1/orders');
$token = json_decode(file_get_contents($c['token_file']), true);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/vnd.api+json',
        'Content-Type: application/vnd.api+json',
        'Authorization: Bearer ' . ($token['accessToken'] ?? ''),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP $code\n$resp\n";
