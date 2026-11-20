<?php
require __DIR__ . '/PosifloraClient.php';
require __DIR__ . '/TildaWebhookHandler.php';
$c = require __DIR__ . '/config.php';
$h = new TildaWebhookHandler($c);
try {
    echo json_encode($h->process([
        'Name' => 'Анна с сайта',
        'Phone' => '+79037654321',
        'Email' => 'test@example.com',
        'payment' => [
            'orderid' => 'ASCII1',
            'amount' => 100,
            'products' => [
                ['name' => 'Мягкая игрушка Зайка белый', 'sku' => '', 'quantity' => 1, 'price' => 500],
            ],
            'sys' => '',
        ],
        'formname' => 'Cart',
    ]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
