<?php
require __DIR__ . '/PosifloraClient.php';
$c = require __DIR__ . '/config.php';
$api = new PosifloraClient($c['posiflora']);
$cats = $api->getAllCategories();
$pub = $api->getAllInventoryItems(['public' => 'true']);
$api->lastIncluded = [];
$all = $api->getAllInventoryItems(['public' => null]);
$api->lastIncluded = [];
$specs = $api->getAllSpecifications(['public' => 'true']);
$specAll = $api->getAllSpecifications(['public' => null]);

$catTitle = [];
foreach ($cats as $cat) {
    $catTitle[$cat['id'] ?? ''] = $cat['attributes']['title'] ?? '';
}

function summarize(array $items, array $catTitle): array
{
    $by = [];
    $zero = 0;
    $sample = [];
    foreach ($items as $it) {
        $a = $it['attributes'] ?? [];
        $cid = $it['relationships']['category']['data']['id'] ?? '';
        $title = $catTitle[$cid] ?? '(none)';
        $by[$title] = ($by[$title] ?? 0) + 1;
        $qty = $a['balance'] ?? $a['quantity'] ?? $a['qty'] ?? null;
        if ($qty !== null && (float)$qty <= 0) {
            $zero++;
        }
        if (count($sample) < 8) {
            $sample[] = [
                'title' => $a['title'] ?? '',
                'public' => $a['public'] ?? null,
                'qty' => $qty,
                'price' => $a['priceMin'] ?? $a['minPrice'] ?? null,
                'cat' => $title,
            ];
        }
    }
    ksort($by);
    return ['count' => count($items), 'zero_qty' => $zero, 'by_cat' => $by, 'sample' => $sample];
}

echo json_encode([
    'cats' => count($cats),
    'inventory_public' => summarize($pub, $catTitle),
    'inventory_all' => ['count' => count($all)],
    'specs_public' => summarize($specs, $catTitle),
    'specs_all' => ['count' => count($specAll)],
    'spec_keys' => array_keys($specs[0]['attributes'] ?? []),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
