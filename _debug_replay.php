<?php
require __DIR__ . '/PosifloraClient.php';
require __DIR__ . '/TildaWebhookHandler.php';
$c = require __DIR__ . '/config.php';
$h = new TildaWebhookHandler($c);
$payload = [
    'Name' => 'Виктор replay',
    'Email' => 'emi96545@gmail.com',
    'Phone' => '+7 (909) 713-27-73',
    'payment' => [
        'sys' => 'none',
        'systranid' => '0',
        'orderid' => '1796885801-replay',
        'products' => [[
            'name' => 'Мишка в свитере сердечко серый, 20 см',
            'quantity' => '1',
            'amount' => '850',
            'externalid' => 'G4gTK1YK3o1PsUemsfiO',
            'price' => '850',
            'sku' => '0d668c55-1c0b-45ba-b36a-4cdd241a6f2c',
        ]],
        'amount' => '850',
    ],
    'formid' => 'form4574684601',
    'formname' => 'Cart',
];
try {
    echo json_encode($h->process($payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n", $e->getFile(), ':', $e->getLine(), "\n";
}
