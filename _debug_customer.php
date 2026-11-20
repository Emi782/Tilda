<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
try {
    $id = $api->findOrCreateCustomer('Анна с сайта', '9037654321', 'anna@example.com');
    echo "customer=$id\n";
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage() . "\n";
}
