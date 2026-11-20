<?php
/**
 * Клиент Posiflora API
 * JSON:API формат, Bearer-токен, автоматическое обновление сессии
 */

class PosifloraClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $tokenFile;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    public function __construct(array $config)
    {
        $this->baseUrl    = rtrim($config['base_url'], '/');
        if (!preg_match('#/api$#i', $this->baseUrl)) {
            $this->baseUrl .= '/api';
        }
        $this->username   = $config['username'];
        $this->password   = $config['password'];
        $this->tokenFile  = $config['token_file'] ?? __DIR__ . '/.posiflora_token.json';
        $this->loadToken();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ── Авторизация ──────────────────────────────────────

    public function login(): array
    {
        $payload = [
            'data' => [
                'type'       => 'sessions',
                'attributes' => [
                    'username' => $this->username,
                    'password' => $this->password,
                ],
            ],
        ];

        $resp = $this->request('POST', '/v1/sessions', $payload, false);

        $this->accessToken  = $resp['data']['attributes']['accessToken']  ?? null;
        $this->refreshToken = $resp['data']['attributes']['refreshToken'] ?? null;

        if (!$this->accessToken) {
            throw new RuntimeException('Авторизация не удалась: нет accessToken в ответе');
        }

        $this->saveToken();
        return $resp;
    }

    public function refresh(): void
    {
        if (!$this->refreshToken) {
            $this->login();
            return;
        }

        $payload = [
            'data' => [
                'type'       => 'sessions',
                'attributes' => [
                    'refreshToken' => $this->refreshToken,
                ],
            ],
        ];

        try {
            $resp = $this->request('PATCH', '/v1/sessions', $payload, false);
            $this->accessToken = $resp['data']['attributes']['accessToken'] ?? null;
            if ($this->accessToken) {
                $this->saveToken();
                return;
            }
        } catch (Exception $e) {
            // ignore — fall through to full login
        }
        $this->login();
    }

    // ── Товары (Inventory Items) ─────────────────────────

    public function getInventoryItems(int $page = 1, int $size = 50, array $extraParams = []): array
    {
        $params = array_merge([
            'page[number]' => $page,
            'page[size]'   => $size,
            'include'      => 'logo,images,category',
        ], $extraParams);
        if (!array_key_exists('public', $extraParams)) {
            $params['public'] = 'true';
        } elseif ($extraParams['public'] === null) {
            unset($params['public']);
        }

        return $this->get('/v1/inventory-items', $params);
    }

    public array $lastIncluded = [];

    public function getAllInventoryItems(array $extraParams = []): array
    {
        $all = [];
        $this->lastIncluded = [];
        $page = 1;
        do {
            $resp = $this->getInventoryItems($page, 50, $extraParams);
            $items = $resp['data'] ?? [];
            $all = array_merge($all, $items);
            $this->lastIncluded = array_merge($this->lastIncluded, $resp['included'] ?? []);
            $total = $resp['meta']['total'] ?? count($all);
            $page++;
        } while (count($all) < $total && count($items) > 0);
        return $all;
    }

    // ── Категории ────────────────────────────────────────

    public function getCategories(int $page = 1, int $size = 100): array
    {
        return $this->get('/v1/categories', [
            'page[number]' => $page,
            'page[size]'   => $size,
        ]);
    }

    public function getAllCategories(): array
    {
        $all = [];
        $page = 1;
        do {
            $resp = $this->getCategories($page, 100);
            $items = $resp['data'] ?? [];
            $all = array_merge($all, $items);
            $total = $resp['meta']['total'] ?? count($all);
            $page++;
        } while (count($all) < $total && count($items) > 0);
        return $all;
    }

    public function getAllPaged(string $path, array $extraParams = [], int $size = 50): array
    {
        $all = [];
        $included = [];
        $page = 1;
        do {
            $params = array_merge([
                'page[number]' => $page,
                'page[size]'   => $size,
            ], $extraParams);
            $resp = $this->get($path, $params);
            $rows = $resp['data'] ?? [];
            $all = array_merge($all, $rows);
            $included = array_merge($included, $resp['included'] ?? []);
            $total = $resp['meta']['total'] ?? count($all);
            $page++;
        } while (count($all) < $total && count($rows) > 0);
        $this->lastIncluded = array_merge($this->lastIncluded, $included);
        return $all;
    }

    public function getAllSpecifications(array $extraParams = []): array
    {
        $params = array_merge([
            'public'  => 'true',
            'include' => 'logo,images,category',
        ], $extraParams);
        if (array_key_exists('public', $extraParams) && $extraParams['public'] === null) {
            unset($params['public']);
        }
        return $this->getAllPaged('/v1/specifications', $params);
    }

    // ── Магазины/склады ──────────────────────────────────

    public function getStores(): array
    {
        $resp = $this->get('/v1/stores', ['page[size]' => 100]);
        return $resp['data'] ?? [];
    }

    // ── Источники заказов ────────────────────────────────

    public function getOrderSources(): array
    {
        $resp = $this->get('/v1/order-sources', ['page[size]' => 100]);
        return $resp['data'] ?? [];
    }

    public function findOrCreateSource(string $title): ?string
    {
        $sources = $this->getOrderSources();
        foreach ($sources as $src) {
            if (($src['attributes']['title'] ?? '') === $title) {
                return $src['id'];
            }
        }
        $payload = [
            'data' => [
                'type'       => 'order-sources',
                'id'         => $this->uuid(),
                'attributes' => ['title' => $title],
            ],
        ];
        $resp = $this->post('/v1/order-sources', $payload);
        return $resp['data']['id'] ?? null;
    }

    public function findOrCreateCustomer(string $name, string $phone, string $email = ''): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }
        if ($digits === '') {
            return null;
        }

        $resp = $this->get('/v1/customers', [
            'search'     => $digits,
            'page[size]' => 10,
        ]);
        foreach ($resp['data'] ?? [] as $row) {
            $p = preg_replace('/\D/', '', (string)($row['attributes']['phone'] ?? ''));
            if ($p === $digits || $p === '7' . $digits || $p === '8' . $digits) {
                return $row['id'];
            }
        }

        $created = $this->post('/v1/customers', [
            'data' => [
                'type' => 'customers',
                'id'   => $this->uuid(),
                'attributes' => [
                    'title'       => $name !== '' ? $name : 'Клиент Tilda',
                    'isPerson'    => true,
                    'status'      => 'on',
                    'countryCode' => 7,
                    'phone'       => $digits,
                    'email'       => $email,
                ],
            ],
        ]);
        return $created['data']['id'] ?? null;
    }

    public function findInventoryItemId(string $sku = '', string $title = ''): ?string
    {
        foreach ([$sku, $title] as $query) {
            $query = trim($query);
            if ($query === '') {
                continue;
            }
            $resp = $this->get('/v1/inventory-items', [
                'search'     => $query,
                'page[size]' => 20,
            ]);
            foreach ($resp['data'] ?? [] as $item) {
                $attrs = $item['attributes'] ?? [];
                $itemTitle = (string)($attrs['title'] ?? '');
                $globalId  = (string)($attrs['globalId'] ?? '');
                if ($sku !== '' && ($globalId === $sku || ($item['id'] ?? '') === $sku)) {
                    return $item['id'];
                }
                if ($title !== '' && mb_strtolower($itemTitle) === mb_strtolower($title)) {
                    return $item['id'];
                }
            }
        }
        return null;
    }

    // ── Заказы ───────────────────────────────────────────

    public function createOrder(array $attributes, array $relationships = []): array
    {
        $data = [
            'type'       => 'orders',
            'id'         => $this->uuid(),
            'attributes' => $attributes,
        ];
        if ($relationships) {
            $data['relationships'] = $relationships;
        }
        return $this->post('/v1/orders', ['data' => $data]);
    }

    public function attachOrderCustomer(string $orderId, string $customerId): array
    {
        return $this->patch("/v1/orders/{$orderId}", [
            'data' => [
                'type' => 'orders',
                'id'   => $orderId,
                'relationships' => [
                    'customer' => [
                        'data' => [
                            'type' => 'customers',
                            'id'   => $customerId,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function addPayment(string $orderId, int $amount, string $date, string $methodId = null): array
    {
        $data = [
            'type'       => 'order-payments',
            'id'         => $this->uuid(),
            'attributes' => [
                'paymentType' => 'payment',
                'date'        => $date,
                'amount'      => $amount,
            ],
        ];
        if ($methodId) {
            $data['relationships']['method']['data'] = [
                'type' => 'payment-methods',
                'id'   => $methodId,
            ];
        }
        return $this->post("/v1/orders/{$orderId}/payments", ['data' => $data]);
    }

    public function getOrders(array $params = []): array
    {
        return $this->get('/v1/orders', $params);
    }

    // ── Низкоуровневые методы ────────────────────────────

    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, null, true, $params);
    }

    public function shopOrigin(): string
    {
        return (string)preg_replace('#/api$#i', '', $this->baseUrl);
    }

    public function getShowcase(string $urlPath): array
    {
        $origin = $this->shopOrigin();
        $q = 'urlPath=' . rawurlencode($urlPath);
        $items = $this->httpJson($origin . '/shop/api/v1/showcase-items?' . $q, false);
        $cats = $this->httpJson($origin . '/shop/api/v1/showcase-categories?' . $q, false);
        return [
            'items' => $items['data'] ?? [],
            'categories' => $cats['data'] ?? [],
            'meta' => $items['meta'] ?? [],
        ];
    }

    public function getBouquet(string $id): array
    {
        return $this->get('/v1/bouquets/' . $id, ['include' => 'images,logo']);
    }

    public function getImage(string $id): array
    {
        return $this->get('/v1/images/' . $id);
    }

    public function downloadBinary(string $url): array
    {
        if ($this->accessToken === null) {
            $this->login();
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: */*',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new RuntimeException("Не удалось скачать файл ({$code}): {$err}");
        }
        return ['body' => $body, 'content_type' => $type];
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body, true);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, $body, true);
    }

    // ── Внутренний запрос ────────────────────────────────

    public function httpJson(string $url, bool $auth = true): array
    {
        if ($auth && !$this->accessToken) {
            $this->login();
        }
        $ch = curl_init($url);
        $headers = ['Accept: application/vnd.api+json, application/json'];
        if ($auth && $this->accessToken) {
            $headers[] = "Authorization: Bearer {$this->accessToken}";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) {
            throw new RuntimeException("cURL GET {$url}: {$error}");
        }
        $decoded = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Некорректный JSON (HTTP {$httpCode}) GET {$url}: " . substr((string)$response, 0, 500));
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("Shop API error (HTTP {$httpCode}) GET {$url}");
        }
        return $decoded;
    }

    private function request(string $method, string $path, ?array $body, bool $auth, array $queryParams = []): array
    {
        $url = $this->baseUrl . $path;
        if ($queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        if ($auth && !$this->accessToken) {
            $this->login();
        }

        $ch = curl_init($url);
        $headers = [
            'Accept: application/vnd.api+json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/vnd.api+json';
        }
        if ($auth && $this->accessToken) {
            $headers[] = "Authorization: Bearer {$this->accessToken}";
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Не удалось закодировать JSON: ' . json_last_error_msg());
            }
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL {$method} {$url}: {$error}");
        }

        // 401 — пробуем обновить токен и повторить
        if ($httpCode === 401 && $auth) {
            $this->refresh();
            return $this->request($method, $path, $body, $auth, $queryParams);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Некорректный JSON в ответе (HTTP {$httpCode}) {$method} {$url}: " . substr((string)$response, 0, 500));
        }

        if ($httpCode >= 400) {
            $msg = 'API error';
            if (isset($decoded['errors'])) {
                $errs = [];
                foreach ($decoded['errors'] as $e) {
                    $ptr = $e['source']['pointer'] ?? '';
                    $detail = $e['detail'] ?? '';
                    $errs[] = trim(($e['title'] ?? '') . ($detail !== '' ? ': ' . $detail : '') . ($ptr ? " [{$ptr}]" : ''));
                }
                $msg = implode('; ', $errs);
            }
            throw new RuntimeException("{$msg} (HTTP {$httpCode})");
        }

        return $decoded;
    }

    public function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }

    // ── Кэш токена ───────────────────────────────────────

    private function loadToken(): void
    {
        if (is_file($this->tokenFile)) {
            $data = json_decode(file_get_contents($this->tokenFile), true);
            if ($data) {
                $this->accessToken  = $data['accessToken']  ?? null;
                $this->refreshToken = $data['refreshToken'] ?? null;
            }
        }
    }

    private function saveToken(): void
    {
        file_put_contents($this->tokenFile, json_encode([
            'accessToken'  => $this->accessToken,
            'refreshToken' => $this->refreshToken,
        ], JSON_PRETTY_PRINT));
    }
}
