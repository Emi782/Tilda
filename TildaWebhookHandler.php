<?php
/**
 * Обработчик webhook от Tilda — создание заказа в Posiflora
 */

class TildaWebhookHandler
{
    private PosifloraClient $api;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->api = new PosifloraClient($config['posiflora']);
    }

    /**
     * Обрабатывает данные заказа из Tilda и создаёт заказ в Posiflora
     */
    public function process(array $data): array
    {
        // ── Извлечение данных заказа из Tilda ──────────────
        $name     = $this->asString($data['Name']   ?? $data['name']   ?? '');
        $email    = $this->asString($data['Email']  ?? $data['email']  ?? '');
        $phone    = $this->asString($data['Phone']  ?? $data['phone']  ?? '');
        $orderId  = $data['payment']['orderid'] ?? $data['payment.orderid'] ?? '';
        $amount   = $data['payment']['amount']   ?? $data['payment.amount'] ?? 0;
        $products = $data['payment']['products'] ?? $data['payment.products'] ?? ($data['products'] ?? '');
        $paySys   = $data['payment']['sys']      ?? $data['payment.sys'] ?? '';

        // Парсим товары из строки products
        // Tilda присылает товары в формате "ID:SKU:Name:Price:Qty" или JSON
        $items = $this->parseProducts($products);

        $sourceId = $this->config['posiflora']['source_id'] ?? '';
        try {
            $tildaSource = $this->api->findOrCreateSource('Tilda');
            if ($tildaSource) {
                $sourceId = $tildaSource;
            }
        } catch (Throwable $e) {
            // оставляем source_id из config
        }

        $customerId = null;
        try {
            $customerId = $this->api->findOrCreateCustomer($name, $phone, $email);
        } catch (Throwable $e) {
            // заказ всё равно создаём, имя/телефон останутся в описании
        }

        // ── Формирование атрибутов заказа ─────────────────
        $phoneParts = $this->splitPhone($phone);
        $docNo = 'T' . date('ymdHi') . (string)random_int(0, 9);
        $orderAttrs = [
            'status'          => 'new',
            'date'            => date('Y-m-d'),
            'docNo'           => $docNo,
            'description'     => $this->buildDescription($data, $items),
            'delivery'        => false,
            'deliveryContact' => $name,
        ];
        if ($phoneParts['number'] !== '') {
            $orderAttrs['deliveryPhoneCode']   = $phoneParts['code'];
            $orderAttrs['deliveryPhoneNumber'] = $phoneParts['number'];
        }
        if ($phone) {
            $orderAttrs['description'] .= "\nТелефон: {$phone}";
        }

        // Если есть email — добавляем в описание
        if ($email) {
            $orderAttrs['description'] .= "\nEmail: {$email}";
        }

        // UTM-метки в описание
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
            if (!empty($data[$field])) {
                $utm[] = "{$field}={$data[$field]}";
            }
        }
        if ($utm) {
            $orderAttrs['description'] .= "\nUTM: " . implode(', ', $utm);
        }

        // ── Отношения ──────────────────────────────────────
        $relationships = [];
        if ($sourceId) {
            $relationships['source']['data'] = [
                'type' => 'order-sources',
                'id'   => $sourceId,
            ];
        }
        $storeId = $this->config['posiflora']['store_id'] ?? '';
        if ($storeId) {
            $relationships['store']['data'] = [
                'type' => 'stores',
                'id'   => $storeId,
            ];
        }
        $order = $this->api->createOrder($orderAttrs, $relationships);
        $posifloraOrderId = $order['data']['id'] ?? null;

        if ($posifloraOrderId && $customerId) {
            try {
                $this->api->attachOrderCustomer($posifloraOrderId, $customerId);
            } catch (Throwable $e) {
                // имя и телефон уже в описании и полях доставки
            }
        }

        // ── Платёж только при реальной онлайн-оплате ──────
        $paidOnline = $paySys !== '' && !in_array(strtolower((string)$paySys), ['none', '0', 'false'], true);
        if ($posifloraOrderId && $amount > 0 && $paidOnline) {
            try {
                $this->api->addPayment(
                    $posifloraOrderId,
                    (int)round((float)$amount),
                    date('Y-m-d')
                );
            } catch (Throwable $e) {
                // заказ уже создан, платёж не должен его откатывать
            }
        }

        return [
            'tilda_order_id'     => $orderId,
            'posiflora_order_id' => $posifloraOrderId,
            'customer_name'      => $name,
            'phone'              => $phone,
            'amount'             => $amount,
            'items_count'        => count($items),
        ];
    }

    /**
     * Парсит строку товаров из Tilda
     * Форматы:
     *   "ID::Name:Price:Qty" или JSON
     */
    private function parseProducts($products): array
    {
        if (empty($products)) return [];

        if (is_array($products)) {
            return $products;
        }

        // Если JSON
        $json = json_decode($products, true);
        if (is_array($json)) {
            return $json;
        }

        // Если строка с разделителями
        $items = [];
        $lines = explode("\n", $products);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $parts = explode(':', $line);
            $items[] = [
                'id'    => $parts[0] ?? '',
                'sku'   => $parts[1] ?? '',
                'name'  => $parts[2] ?? '',
                'price' => $parts[3] ?? 0,
                'qty'   => $parts[4] ?? 1,
            ];
        }
        return $items;
    }

    private function buildOrderLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $title = (string)($item['name'] ?? $item['title'] ?? '');
            $sku   = (string)($item['sku'] ?? $item['externalid'] ?? $item['id'] ?? '');
            $qty   = (float)($item['qty'] ?? $item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? $item['amount'] ?? 0);
            if ($qty <= 0) {
                $qty = 1;
            }
            $itemId = $this->api->findInventoryItemId($sku, $title);
            if (!$itemId) {
                continue;
            }
            $sum = number_format($qty * $price, 2, '.', '');
            $priceStr = number_format($price, 2, '.', '');
            $qtyStr = number_format($qty, 2, '.', '');
            $lines[] = [
                'type' => 'order-lines',
                'id'   => $this->api->uuid(),
                'attributes' => [
                    'qty'                     => $qtyStr,
                    'price'                   => $priceStr,
                    'amount'                  => $sum,
                    'totalAmount'             => $sum,
                    'totalAmountWithDiscount' => $sum,
                    'discountPrice'           => $priceStr,
                    'manualSetting'           => true,
                ],
                'relationships' => [
                    'item' => [
                        'data' => [
                            'type' => 'inventory-items',
                            'id'   => $itemId,
                        ],
                    ],
                ],
            ];
        }
        return $lines;
    }

    private function buildDescription(array $data, array $items): string
    {
        $desc = "Заказ из Tilda #" . ($data['payment']['orderid'] ?? $data['payment.orderid'] ?? '');

        if ($items) {
            $desc .= "\n\nТовары:";
            foreach ($items as $item) {
                $name = $item['name'] ?? $item['title'] ?? $item['id'] ?? '';
                $qty  = $item['qty'] ?? $item['quantity'] ?? 1;
                $price = $item['price'] ?? 0;
                $desc .= "\n  - {$name} x{$qty} = {$price} руб";
            }
        }

        if (!empty($data['formname'])) {
            $desc .= "\n\nФорма: " . $data['formname'];
        }

        return $desc;
    }

    private function asString($value): string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? $value['phone'] ?? $value[0] ?? implode('', $value);
        }
        return trim((string)$value);
    }

    /**
     * Posiflora: код страны отдельно, в номере только цифры (3–15).
     * РФ: код 7, номер 10 цифр. Маска Тильды: +7 (999) 999-99-99.
     */
    private function splitPhone(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return ['code' => '7', 'number' => ''];
        }
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            return ['code' => '7', 'number' => substr($digits, 1)];
        }
        if (strlen($digits) === 10) {
            return ['code' => '7', 'number' => $digits];
        }
        if (strlen($digits) > 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $rest = substr($digits, 1);
            if (strlen($rest) >= 10) {
                return ['code' => '7', 'number' => substr($rest, 0, 10)];
            }
        }
        if (strlen($digits) >= 3 && strlen($digits) <= 15) {
            return ['code' => '7', 'number' => $digits];
        }
        return ['code' => '7', 'number' => ''];
    }
}
