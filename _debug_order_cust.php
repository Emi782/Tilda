<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$cust = $api->findOrCreateCustomer('Анна с сайта', '9037654321', 'anna@example.com');
echo "cust=$cust\n";
try {
    $order = $api->createOrder(
        [
            'status' => 'new',
            'date' => date('Y-m-d'),
            'docNo' => 'T' . date('ymdHi') . '7',
            'description' => "Товар: Зайка белый x1 = 500 руб\nТелефон: +7 903 765-43-21",
            'delivery' => false,
            'deliveryContact' => 'Анна с сайта',
            'deliveryPhoneCode' => '7',
            'deliveryPhoneNumber' => '9037654321',
        ],
        [
            'store' => ['data' => ['type' => 'stores', 'id' => $c['posiflora']['store_id']]],
            'source' => ['data' => ['type' => 'order-sources', 'id' => $api->findOrCreateSource('Tilda')]],
            'customer' => ['data' => ['type' => 'customers', 'id' => $cust]],
        ]
    );
    echo 'order=' . ($order['data']['id'] ?? '') . ' doc=' . ($order['data']['attributes']['docNo'] ?? '') . "\n";
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage() . "\n";
}
