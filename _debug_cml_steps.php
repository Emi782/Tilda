<?php
require __DIR__ . '/TildaCommerceMLClient.php';
$c = require __DIR__ . '/config.php';
$x = new TildaCommerceMLClient($c['tilda_commerceml']);
try {
    echo "1 checkauth\n" . $x->checkAuth() . "\n";
    echo "2 init\n" . $x->init() . "\n";
    $tiny = '<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация ВерсияСхемы="2.05" ДатаФормирования="2026-11-04T00:00:00"><Классификатор><Наименование>t</Наименование><Группы></Группы></Классификатор><Каталог СодержитТолькоИзменения="false"><Наименование>t</Наименование><Товары></Товары></Каталог></КоммерческаяИнформация>';
    echo "3 tiny file " . strlen($tiny) . "\n" . $x->uploadFile('import0_1.xml', $tiny) . "\n";
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
