<?php
/**
 * CLI-утилита для управления коннектором Posiflora <-> Tilda
 * 
 * Команды:
 *   php cli.php auth         — авторизация в Posiflora, вывод чистый JSON
 *   php cli.php stores       — список магазинов (нужен store_id для config.php)
 *   php cli.php sources      — список источников заказов
 *   php cli.php export       — выгрузка каталога в CommerceML (import.xml + offers.xml + catalog.zip)
 *   php cli.php orders       — последние заказы из Posiflora
 *   php cli.php test-webhook — симуляция webhook от Tilda
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PosifloraClient.php';
require_once __DIR__ . '/CommerceMLExporter.php';

$config = require __DIR__ . '/config.php';
$command = $argv[1] ?? 'help';

function output($data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($code);
}

function tilda_category_tree_ids(array $categories, string $rootTitle): array
{
    $rootId = '';
    foreach ($categories as $cat) {
        if (($cat['attributes']['title'] ?? '') === $rootTitle && empty($cat['relationships']['parent']['data']['id'])) {
            $rootId = $cat['id'] ?? '';
            break;
        }
    }
    if ($rootId === '') {
        return [];
    }
    $ids = [$rootId => true];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($categories as $cat) {
            $id = $cat['id'] ?? '';
            $parent = $cat['relationships']['parent']['data']['id'] ?? '';
            if ($id !== '' && $parent !== '' && isset($ids[$parent]) && !isset($ids[$id])) {
                $ids[$id] = true;
                $changed = true;
            }
        }
    }
    return array_keys($ids);
}

function tilda_showcase_photo_url(string $photoId, array $meta): string
{
    $photoId = trim($photoId);
    if ($photoId === '') {
        return '';
    }
    $img = $meta['image'] ?? [];
    $variant = (string)($img['variant'] ?? '_shop');
    $ext = (string)($img['extension'] ?? '.jpg');
    $path = $photoId[0] . '/' . substr($photoId, 1) . $variant . $ext;
    global $config;
    $base = rtrim((string)($config['public_base'] ?? 'https://startergpt.ru/tilda'), '/');
    // r меняет ссылку, чтобы Тильда заново скачала фото у уже существующей карточки.
    return $base . '/shop-photo.php?p=' . rawurlencode($path) . '&r=3';
}

function tilda_showcase_photo_urls(array $attrs, array $meta): array
{
    $out = [];
    foreach ($attrs['photos'] ?? [] as $photoId) {
        $url = tilda_showcase_photo_url((string)$photoId, $meta);
        if ($url !== '') {
            $out[] = $url;
        }
    }
    return array_values(array_unique($out));
}

function tilda_showcase_photo(array $attrs, array $meta): string
{
    $urls = tilda_showcase_photo_urls($attrs, $meta);
    return $urls[0] ?? '';
}

function tilda_shop_categories(array $shopCats): array
{
    $out = [];
    foreach ($shopCats as $cat) {
        $id = $cat['id'] ?? '';
        $title = $cat['attributes']['title'] ?? '';
        if ($id === '' || ($cat['attributes']['hidden'] ?? false)) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'type' => 'categories',
            'attributes' => ['title' => $title],
            'relationships' => ['parent' => ['data' => null]],
        ];
    }
    return $out;
}

function tilda_item_photo_list(array $item): array
{
    $attrs = $item['attributes'] ?? [];
    $photos = [];
    foreach ($attrs['pictureUrls'] ?? [] as $url) {
        if (is_string($url) && $url !== '') {
            $photos[] = $url;
        }
    }
    if ($photos === [] && !empty($attrs['pictureUrl']) && is_string($attrs['pictureUrl'])) {
        $photos[] = $attrs['pictureUrl'];
    }
    return $photos;
}

function tilda_visible_items(array $items, array $hiddenKeys): array
{
    $hidden = [];
    foreach ($hiddenKeys as $key) {
        $key = mb_strtolower(trim((string)$key));
        if ($key !== '') {
            $hidden[$key] = true;
        }
    }
    $out = [];
    foreach ($items as $item) {
        if (tilda_item_photo_list($item) === []) {
            continue;
        }
        $id = mb_strtolower(trim((string)($item['id'] ?? '')));
        $title = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)($item['attributes']['title'] ?? '')) ?? ''));
        if (($id !== '' && isset($hidden[$id])) || ($title !== '' && isset($hidden[$title]))) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

function tilda_hide_item(array $item): array
{
    $item['attributes']['tildaActive'] = false;
    $item['attributes']['public'] = false;
    $item['attributes']['balance'] = 0;
    $price = (float)($item['attributes']['priceMax'] ?? 0);
    if ($price <= 0) {
        $price = (float)($item['attributes']['priceMin'] ?? 0);
    }
    $item['attributes']['priceMin'] = $price;
    $item['attributes']['priceMax'] = $price;
    return $item;
}

/** Старый вариант: публичный склад + рецепты. php cli.php export-warehouse */
function tilda_collect_items_warehouse(PosifloraClient $api, array $categories): array
{
    $public = $api->getAllInventoryItems(['public' => 'true']);
    $included = $api->lastIncluded;
    $all = $api->getAllInventoryItems(['public' => null]);
    $included = array_merge($included, $api->lastIncluded);
    $specs = $api->getAllSpecifications(['public' => null]);
    $included = array_merge($included, $api->lastIncluded);
    $flowerIds = array_flip(tilda_category_tree_ids($categories, 'Цветы'));

    $byId = [];
    foreach ($public as $item) {
        $item['attributes']['tildaActive'] = true;
        $byId[$item['id']] = $item;
    }
    foreach (tilda_normalize_specs($specs) as $spec) {
        $id = $spec['id'] ?? '';
        if ($id === '') {
            continue;
        }
        $price = (float)($spec['attributes']['priceMin'] ?? 0);
        if ($price > 0) {
            $spec['attributes']['tildaActive'] = true;
            $byId[$id] = $spec;
        } else {
            $byId[$id] = tilda_hide_item($spec);
        }
    }
    foreach ($all as $item) {
        $id = $item['id'] ?? '';
        if ($id === '' || isset($byId[$id])) {
            continue;
        }
        $catId = $item['relationships']['category']['data']['id'] ?? '';
        if (isset($flowerIds[$catId])) {
            $byId[$id] = tilda_hide_item($item);
        }
    }
    $api->lastIncluded = $included;
    return array_values($byId);
}

function tilda_collect_items(PosifloraClient $api, array &$categories, string $shopUrlPath): array
{
    $shop = $api->getShowcase($shopUrlPath);
    $meta = $shop['meta'] ?? [];
    $categories = tilda_shop_categories($shop['categories'] ?? []);

    $wanted = [];
    $included = [];
    foreach ($shop['items'] ?? [] as $row) {
        $attrs = $row['attributes'] ?? [];
        $kind = $attrs['kind'] ?? 'product';
        $rawId = (string)($row['id'] ?? '');
        $id = $rawId;
        if ($kind === 'product' && str_starts_with($rawId, 'fwr-')) {
            $id = substr($rawId, 4);
        }
        if ($id === '') {
            continue;
        }
        $price = (float)($attrs['price'] ?? 0);
        $title = trim((string)($attrs['title'] ?? ''));
        $catId = (string)($attrs['showcaseCategoryId'] ?? '');
        $photos = tilda_showcase_photo_urls($attrs, $meta);
        $item = [
            'id' => $id,
            'type' => $kind === 'bouquet' ? 'bouquets' : 'inventory-items',
            'attributes' => [
                'title' => $title,
                'description' => (string)($attrs['description'] ?? ''),
                'priceMin' => $price,
                'priceMax' => $price,
                'balance' => 1,
                'tildaActive' => true,
                'public' => true,
                'pictureUrl' => $photos[0] ?? '',
                'pictureUrls' => $photos,
                'shopOrder' => (int)($attrs['order'] ?? 0),
            ],
            'relationships' => [
                'category' => [
                    'data' => $catId !== '' ? ['type' => 'categories', 'id' => $catId] : null,
                ],
            ],
        ];
        if ($kind === 'bouquet') {
            try {
                $bq = $api->getBouquet($id);
                $bqAttrs = $bq['data']['attributes'] ?? [];
                if (!empty($bqAttrs['description'])) {
                    $item['attributes']['description'] = $bqAttrs['description'];
                }
                if ($photos === []) {
                    $photoIds = [];
                    $logoId = (string)($bq['data']['relationships']['logo']['data']['id'] ?? '');
                    if ($logoId !== '') {
                        $photoIds[] = $logoId;
                    }
                    foreach ($bq['included'] ?? [] as $inc) {
                        if (($inc['type'] ?? '') === 'images' && !empty($inc['id'])) {
                            $photoIds[] = (string)$inc['id'];
                        }
                    }
                    $photos = [];
                    foreach ($photoIds as $photoId) {
                        $url = tilda_showcase_photo_url($photoId, $meta);
                        if ($url !== '') {
                            $photos[] = $url;
                        }
                    }
                    $photos = array_values(array_unique($photos));
                    $item['attributes']['pictureUrl'] = $photos[0] ?? '';
                    $item['attributes']['pictureUrls'] = $photos;
                }
                $included = array_merge($included, $bq['included'] ?? []);
            } catch (Throwable $e) {
                // витрина уже дала название, цену и фото
            }
        }
        $wanted[$id] = $item;
    }

    $api->lastIncluded = $included;
    $items = array_values($wanted);
    usort($items, static function (array $a, array $b): int {
        $pa = (float)($a['attributes']['priceMin'] ?? 0);
        $pb = (float)($b['attributes']['priceMin'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        return strcasecmp((string)($a['attributes']['title'] ?? ''), (string)($b['attributes']['title'] ?? ''));
    });
    return $items;
}

function tilda_normalize_specs(array $specs): array
{
    $out = [];
    foreach ($specs as $spec) {
        $attrs = $spec['attributes'] ?? [];
        $price = (float)($attrs['minPrice'] ?? 0);
        if ($price <= 0) {
            $price = (float)($attrs['maxPrice'] ?? 0);
        }
        $attrs['priceMin'] = $price;
        $attrs['priceMax'] = (float)($attrs['maxPrice'] ?? $price);
        $spec['attributes'] = $attrs;
        $out[] = $spec;
    }
    return $out;
}

function error(string $msg, int $code = 1): void
{
    fwrite(STDERR, json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit($code);
}

try {
    $api = new PosifloraClient($config['posiflora']);

    switch ($command) {

        case 'auth':
            $resp = $api->login();
            $probe = $api->get('/v1/stores', ['page[size]' => 1]);
            output([
                'status'  => 'ok',
                'message' => 'Авторизация успешна (API-ключ)',
                'stores'  => isset($probe['data']) ? count((array)$probe['data']) : 0,
                'session_id' => $resp['data']['id'] ?? null,
                'access_token'  => $resp['data']['attributes']['accessToken'] ?? null,
                'refresh_token' => $resp['data']['attributes']['refreshToken'] ?? null,
                'expires_at'    => $resp['data']['attributes']['expireAt'] ?? null,
            ]);

        case 'stores':
            $stores = $api->getStores();
            $list = [];
            foreach ($stores as $s) {
                $list[] = [
                    'id'    => $s['id'],
                    'title' => $s['attributes']['title'] ?? '',
                    'city'  => $s['attributes']['addressCity'] ?? '',
                ];
            }
            output(['stores' => $list]);

        case 'sources':
            $sources = $api->getOrderSources();
            $list = [];
            foreach ($sources as $s) {
                $list[] = [
                    'id'    => $s['id'],
                    'title' => $s['attributes']['title'] ?? '',
                ];
            }
            output(['order_sources' => $list]);

        case 'stats':
            $cats = $api->getAllCategories();
            $pub = $api->getAllInventoryItems(['public' => 'true']);
            $api->lastIncluded = [];
            $allInv = $api->getAllInventoryItems(['public' => null]);
            $api->lastIncluded = [];
            $specs = $api->getAllSpecifications(['public' => 'true']);
            $byCat = [];
            foreach (array_merge($pub, $specs) as $it) {
                $cid = $it['relationships']['category']['data']['id'] ?? '';
                $byCat[$cid] = ($byCat[$cid] ?? 0) + 1;
            }
            $catList = [];
            foreach ($cats as $c) {
                $id = $c['id'] ?? '';
                $catList[] = [
                    'title' => $c['attributes']['title'] ?? '',
                    'id' => $id,
                    'parent' => $c['relationships']['parent']['data']['id'] ?? null,
                    'public_count' => $c['attributes']['countPublicItems'] ?? null,
                    'in_export' => $byCat[$id] ?? 0,
                ];
            }
            output([
                'categories' => count($cats),
                'inventory_public' => count($pub),
                'inventory_all' => count($allInv),
                'specifications_public' => count($specs),
                'cats' => $catList,
            ]);

        case 'export-warehouse':
            require_once __DIR__ . '/TildaYmlExporter.php';
            $categories = $api->getAllCategories();
            $items = tilda_collect_items_warehouse($api, $categories);
            $exporter = new CommerceMLExporter($config['export_dir']);
            $files = $exporter->generate($categories, $items, $api->lastIncluded);
            $yml = (new TildaYmlExporter($config['export_dir']))->generate($categories, $items, $api->lastIncluded);
            output([
                'status' => 'ok',
                'message' => 'Сохранённый складской вариант выгружен в файлы (без отправки в Тильду)',
                'products' => count($items),
                'files' => array_merge($files, $yml),
            ]);

        case 'export':
            require_once __DIR__ . '/TildaYmlExporter.php';
            $categories = $api->getAllCategories();
            $items = tilda_collect_items($api, $categories, $config['posiflora']['shop_url_path'] ?? 'daribuzuluk');

            $exporter = new CommerceMLExporter($config['export_dir']);
            $files = $exporter->generate($categories, $items, $api->lastIncluded);
            $yml = (new TildaYmlExporter($config['export_dir']))->generate($categories, $items, $api->lastIncluded);
            $files = array_merge($files, $yml);
            $ymlPublic = __DIR__ . '/catalog.yml';
            if (!empty($files['catalog.yml']) && is_file($files['catalog.yml'])) {
                copy($files['catalog.yml'], $ymlPublic);
                $files['catalog.yml_public'] = $ymlPublic;
            }

            $response = [
                'status'    => 'ok',
                'message'   => 'Каталог выгружен в CommerceML / YML / CSV',
                'categories' => count($categories),
                'products'  => count($items),
                'files'     => $files,
            ];
            if (isset($files['zip_error'])) {
                $response['warning'] = 'catalog.zip не создан: ' . $files['zip_error'];
                $response['status']  = 'partial';
            }
            output($response);

        case 'sync-commerceml':
            require_once __DIR__ . '/TildaCommerceMLClient.php';
            $lockPath = $config['export_dir'] . '/commerceml.lock';
            if (!is_dir($config['export_dir'])) {
                mkdir($config['export_dir'], 0755, true);
            }
            $lock = fopen($lockPath, 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                error('CommerceML sync already running');
            }
            try {
                $categories = $api->getAllCategories();
                $items = tilda_collect_items($api, $categories, $config['posiflora']['shop_url_path'] ?? 'daribuzuluk');
                require_once __DIR__ . '/TildaYmlImporter.php';
                $store = new TildaYmlImporter($config['tilda_store'] ?? []);
                $publication = $store->syncAvailability($items);
                $visible = tilda_visible_items($items, $publication['hidden_keys'] ?? []);
                $files = (new CommerceMLExporter($config['export_dir']))->generate($categories, $visible, $api->lastIncluded);
                $client = new TildaCommerceMLClient($config['tilda_commerceml']);
                $cmlError = null;
                $log = null;
                try {
                    $log = $client->sync($files['import.xml'], $files['offers.xml']);
                } catch (Throwable $e) {
                    $cmlError = $e;
                }
                $publication['hidden'] = $store->applyHidden();
                if ($cmlError !== null) {
                    throw $cmlError;
                }
                output([
                    'status'     => 'ok',
                    'message'    => 'Каталог залит в Тильду через CommerceML',
                    'categories' => count($categories),
                    'products'  => count($visible),
                    'skipped'   => count($items) - count($visible),
                    'tilda'      => $log,
                    'publication' => $publication,
                ]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

        case 'sync-tilda':
        case 'sync-yml':
            require_once __DIR__ . '/TildaYmlExporter.php';
            require_once __DIR__ . '/TildaYmlImporter.php';
            $categories = $api->getAllCategories();
            $items = tilda_collect_items($api, $categories, $config['posiflora']['shop_url_path'] ?? 'daribuzuluk');
            $files = (new TildaYmlExporter($config['export_dir']))->generate($categories, $items, $api->lastIncluded);
            $ymlPublic = __DIR__ . '/catalog.yml';
            copy($files['catalog.yml'], $ymlPublic);
            $importer = new TildaYmlImporter($config['tilda_store'] ?? []);
            $log = $importer->import($files['catalog.yml']);
            output([
                'status'     => 'ok',
                'message'    => 'Каталог залит в Тильду через YML',
                'categories' => count($categories),
                'products'   => count($items),
                'tilda'      => $log,
            ]);

        case 'sync-shop-view':
            require_once __DIR__ . '/TildaYmlImporter.php';
            $categories = $api->getAllCategories();
            $items = tilda_collect_items($api, $categories, $config['posiflora']['shop_url_path'] ?? 'daribuzuluk');
            $store = new TildaYmlImporter($config['tilda_store'] ?? []);
            $applied = $store->applyShopPresentation($items);
            output([
                'status' => 'ok',
                'message' => 'Галереи и сортировка как на витрине',
                'products' => count($items),
                'tilda' => $applied,
            ]);

        case 'orders':
            $params = [];
            if (isset($argv[2])) {
                $params['page[size]'] = (int)$argv[2];
            } else {
                $params['page[size]'] = 20;
            }
            $resp = $api->getOrders($params);
            $list = [];
            foreach ($resp['data'] ?? [] as $order) {
                $attrs = $order['attributes'] ?? [];
                $list[] = [
                    'id'     => $order['id'],
                    'status' => $attrs['status'] ?? '',
                    'date'   => $attrs['date'] ?? '',
                    'contact'=> $attrs['deliveryContact'] ?? '',
                    'phone'  => $attrs['deliveryPhoneNumber'] ?? '',
                    'description' => mb_substr($attrs['description'] ?? '', 0, 100),
                ];
            }
            output([
                'orders' => $list,
                'total'  => $resp['meta']['total'] ?? count($list),
            ]);

        case 'test-webhook':
            // Симуляция webhook от Tilda
            require_once __DIR__ . '/TildaWebhookHandler.php';
            $testData = [
                'Name'   => 'Тестовый клиент',
                'Phone'  => '+7 999 123 45 67',
                'Email'  => 'test@example.com',
                'payment' => [
                    'orderid'  => 'TEST-001',
                    'amount'   => 1500,
                    'products' => "1::Букет роз:1500:1",
                    'sys'      => 'card',
                ],
                'formname' => 'Корзина Tilda',
            ];
            $handler = new TildaWebhookHandler($config);
            $result = $handler->process($testData);
            output(['status' => 'ok', 'result' => $result]);

        case 'help':
        default:
            output([
                'usage' => 'php cli.php <command>',
                'commands' => [
                    'auth'         => 'Авторизация в Posiflora (чистый JSON)',
                    'stores'       => 'Список магазинов — найдите store_id',
                    'sources'      => 'Список источников заказов',
                    'export'            => 'Выгрузка витрины Posiflora Shop в файлы (без отправки)',
                    'export-warehouse'  => 'Старый вариант: публичный склад, только файлы',
                    'sync-commerceml'   => 'Выгрузка и заливка в Тильду через CommerceML (без капчи)',
                    'sync-tilda'        => 'Выгрузка и YML-заливка (нужен логин Тильды)',
                    'sync-yml'          => 'То же, что sync-tilda',
                    'orders'       => 'Последние заказы из Posiflora',
                    'test-webhook' => 'Тест webhook: создание заказа в Posiflora',
                ],
            ]);
    }
} catch (Exception $e) {
    error($e->getMessage());
}
