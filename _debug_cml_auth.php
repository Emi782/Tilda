<?php
require __DIR__ . '/TildaCommerceMLClient.php';
$c = require __DIR__ . '/config.php';
$x = new TildaCommerceMLClient($c['tilda_commerceml']);
echo "CHECKAUTH:\n" . $x->checkAuth() . "\n---\n";
echo "INIT:\n" . $x->init() . "\n";
