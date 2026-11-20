<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$r = $api->getInventoryItems(1, 5);
foreach ($r['data'] ?? [] as $i) {
    echo ($i['id'] ?? '') . "\t" . ($i['attributes']['title'] ?? '') . "\n";
}
