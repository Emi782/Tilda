<?php
/**
 * Заливка catalog.yml в Тильду (тот же /store/submit/, что в кабинете).
 * Логин: email + пароль аккаунта Тильды, сессия в cookie-файле.
 */

class TildaYmlImporter
{
    private string $email;
    private string $password;
    private string $projectId;
    private string $cookieFile;
    private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    /** @var array<string, array> карточки, которые нужно выключить после обмена */
    private array $hidePayloads = [];

    public function __construct(array $config)
    {
        $this->email      = trim((string)($config['email'] ?? ''));
        $this->password   = (string)($config['password'] ?? '');
        $this->projectId  = (string)($config['projectid'] ?? '');
        $this->cookieFile = $config['cookie_file'] ?? sys_get_temp_dir() . '/tilda_store_cookie.txt';

        if ($this->email === '' || $this->password === '' || $this->projectId === '') {
            throw new RuntimeException('В config.php задайте tilda_store email, password и projectid (аккаунт Тильды)');
        }

        $dir = dirname($this->cookieFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Смотрит кабинет Тильды и запоминает карточки, которых не должно быть на сайте:
     * без картинки, с нулевым остатком («Нет в наличии») или уже не на витрине Posiflora.
     * Саму запись делает applyHidden() — её вызывают после CommerceML, чтобы обмен не включил карточку обратно.
     */
    public function syncAvailability(array $items): array
    {
        $this->hidePayloads = [];
        $this->ensureLoggedIn();
        $this->touchStore();

        $bySku = [];
        $byTitle = [];
        foreach ($items as $item) {
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
            $keys = [];
            foreach ([(string)($item['id'] ?? ''), (string)($attrs['globalId'] ?? '')] as $raw) {
                $key = mb_strtolower(trim($raw));
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
            $title = $this->normTitle((string)($attrs['title'] ?? ''));
            $rec = ['photos' => $photos, 'keys' => $keys, 'title' => $title];
            foreach ($keys as $key) {
                $bySku[$key] = $rec;
            }
            if ($title !== '') {
                $byTitle[$title] = $rec;
            }
        }

        $hidden = [];
        $noPhoto = 0;
        $outOfStock = 0;
        $notOnShowcase = 0;
        $kept = 0;
        foreach ($this->listProducts() as $p) {
            $uid = (string)($p['uid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $sku = mb_strtolower(trim((string)($p['sku'] ?? $p['externalid'] ?? '')));
            $title = $this->normTitle((string)($p['title'] ?? ''));
            $shop = ($sku !== '' && isset($bySku[$sku])) ? $bySku[$sku] : (($title !== '' && isset($byTitle[$title])) ? $byTitle[$title] : null);
            $sourceOk = $shop !== null && ($shop['photos'] ?? []) !== [];
            $stockOut = $this->isOutOfStock($p);

            if ($sourceOk && !$stockOut && $this->listHasPicture($p) && !$this->isOff((string)($p['off'] ?? ''))) {
                $kept++;
                continue;
            }

            try {
                $prod = $this->storeJson([
                    'comm' => 'getproduct',
                    'projectid' => $this->projectId,
                    'productuid' => $uid,
                ]);
            } catch (Throwable $e) {
                if ($sourceOk && !$stockOut) {
                    $kept++;
                }
                continue;
            }
            if (!$stockOut) {
                $stockOut = $this->isOutOfStock($prod);
            }
            $hasPicture = $this->productHasPicture($prod);

            if ($sourceOk && !$stockOut && $hasPicture) {
                if ($this->isOff((string)($prod['off'] ?? ''))) {
                    $this->saveProductState($uid, true, null, $prod);
                }
                $kept++;
                continue;
            }

            if (!$sourceOk) {
                $notOnShowcase++;
            } elseif ($stockOut) {
                $outOfStock++;
            } else {
                $noPhoto++;
            }
            $this->rememberHidden($hidden, $p, $shop);
            $this->hidePayloads[$uid] = $prod;
        }

        return [
            'no_photo' => $noPhoto,
            'out_of_stock' => $outOfStock,
            'not_on_showcase' => $notOnShowcase,
            'kept' => $kept,
            'hidden_keys' => array_keys($hidden),
        ];
    }

    public function applyHidden(): int
    {
        $n = 0;
        foreach ($this->hidePayloads as $uid => $prod) {
            $this->saveProductState((string)$uid, false, null, $prod);
            $n++;
        }
        return $n;
    }

    public function import(string $ymlPath): array
    {
        if (!is_file($ymlPath)) {
            throw new RuntimeException('Нет catalog.yml');
        }

        $this->login();
        $this->touchStore();

        $yml = file_get_contents($ymlPath);
        if ($yml === false || $yml === '') {
            throw new RuntimeException('catalog.yml пустой');
        }

        $boundary = '----TildaYml' . bin2hex(random_bytes(8));
        $body  = $this->multipart($boundary, [
            'comm'       => 'startimport',
            'projectid'  => $this->projectId,
            'format'     => 'yml',
            'filename'   => 'catalog.yml',
            'importphoto'=> '1',
        ], 'catalog.yml', $yml);

        $start = $this->request('POST', 'https://store.tilda.ru/store/submit/', $body, [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ]);
        $startJson = json_decode($start, true);
        if (!is_array($startJson) || empty($startJson['taskid'])) {
            throw new RuntimeException('Tilda startimport HTTP ' . $this->lastHttpCode . ': ' . substr($start, 0, 500) . ' hdr=' . substr($this->lastHeaders, 0, 300));
        }

        $taskId = (string)$startJson['taskid'];
        $this->request('POST', 'https://store.tilda.ru/store/submit/', http_build_query([
            'comm'      => 'processimport',
            'taskid'    => $taskId,
            'projectid' => $this->projectId,
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        $result = null;
        for ($i = 0; $i < 90; $i++) {
            sleep(1);
            $check = $this->request('POST', 'https://store.tilda.ru/store/submit/', http_build_query([
                'comm'         => 'checkimport',
                'projectid'    => $this->projectId,
                'taskworkerid' => $taskId,
            ]), ['Content-Type: application/x-www-form-urlencoded']);
            $check = trim($check);
            if ($check !== '' && ($check[0] === '{' || $check[0] === '[')) {
                $result = json_decode($check, true);
                break;
            }
            if (stripos($check, 'ERROR') === 0) {
                throw new RuntimeException('Tilda import: ' . $check);
            }
        }
        if (!is_array($result)) {
            throw new RuntimeException('Tilda import: нет ответа о завершении');
        }
        return [
            'taskid' => $taskId,
            'result' => $result,
        ];
    }

    private function rememberHidden(array &$hidden, array $p, ?array $shop): void
    {
        foreach (['sku', 'externalid'] as $key) {
            $value = mb_strtolower(trim((string)($p[$key] ?? '')));
            if ($value !== '') {
                $hidden[$value] = true;
            }
        }
        $title = $this->normTitle((string)($p['title'] ?? ''));
        if ($title !== '') {
            $hidden[$title] = true;
        }
        if ($shop === null) {
            return;
        }
        foreach ($shop['keys'] ?? [] as $key) {
            if ($key !== '') {
                $hidden[$key] = true;
            }
        }
        if (($shop['title'] ?? '') !== '') {
            $hidden[$shop['title']] = true;
        }
    }

    private function normTitle(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? ''));
    }

    private function isOff(string $off): bool
    {
        $off = strtolower(trim($off));
        return in_array($off, ['y', 'yes', '1', 'true'], true);
    }

    private function isOutOfStock(array $row): bool
    {
        if (!array_key_exists('quantity', $row)) {
            return false;
        }
        $raw = $row['quantity'];
        if ($raw === '' || $raw === null) {
            return false;
        }
        return (float)str_replace(',', '.', (string)$raw) <= 0;
    }

    private function listHasPicture(array $p): bool
    {
        foreach (['img', 'image', 'photo', 'thumb', 'picture'] as $key) {
            $val = $p[$key] ?? '';
            if (is_string($val) && stripos($val, 'http') === 0) {
                return true;
            }
        }
        return isset($p['gallery']) && $this->hasPicture($p['gallery']);
    }

    private function productHasPicture(array $prod): bool
    {
        if ($this->hasPicture($prod['gallery'] ?? [])) {
            return true;
        }
        foreach (['img', 'image', 'photo', 'thumb', 'picture'] as $key) {
            $val = $prod[$key] ?? '';
            if (is_string($val) && stripos($val, 'http') === 0) {
                return true;
            }
        }
        return false;
    }

    private function hasPicture($gallery): bool
    {
        if (is_string($gallery)) {
            $decoded = json_decode($gallery, true);
            $gallery = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($gallery)) {
            return false;
        }
        foreach ($gallery as $row) {
            $img = '';
            if (is_array($row)) {
                $img = (string)($row['img'] ?? $row['url'] ?? '');
            } elseif (is_string($row)) {
                $img = $row;
            }
            if ($img !== '' && stripos($img, 'http') === 0) {
                return true;
            }
        }
        return false;
    }

    private function saveProductState(string $uid, bool $published, ?array $gallery, ?array $prod = null): void
    {
        if ($prod === null) {
            $prod = $this->storeJson([
                'comm' => 'getproduct',
                'projectid' => $this->projectId,
                'productuid' => $uid,
            ]);
        }
        $qty = (string)($prod['quantity'] ?? '');
        if ($published && ($qty === '' || (int)$qty <= 0)) {
            $qty = '1';
        }
        if ($gallery === null) {
            $gallery = $prod['gallery'] ?? [];
        }
        if (!is_string($gallery)) {
            $gallery = json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->storeForm([
            'comm' => 'saveproduct',
            'projectid' => $this->projectId,
            'productuid' => $uid,
            'title' => (string)($prod['title'] ?? ''),
            'price' => (string)($prod['price'] ?? ''),
            'sku' => (string)($prod['sku'] ?? ''),
            'quantity' => $qty,
            'partuids' => (string)($prod['partuids'] ?? ''),
            'descr' => (string)($prod['descr'] ?? ''),
            'text' => (string)($prod['text'] ?? ''),
            'off' => $published ? '' : 'y',
            'gallery' => $gallery,
        ]);
    }

    public function listProducts(): array
    {
        $all = [];
        for ($slice = 1; $slice <= 30; $slice++) {
            $list = $this->storeJson([
                'comm' => 'getproductslist',
                'projectid' => $this->projectId,
                'slicesize' => '100',
                'slice' => (string)$slice,
            ]);
            $products = $list['products'] ?? [];
            if ($products === []) {
                break;
            }
            foreach ($products as $p) {
                $all[] = $p;
            }
            if (count($products) < 100) {
                break;
            }
        }
        return $all;
    }

    private function ensureLoggedIn(): void
    {
        if (is_file($this->cookieFile) && filesize($this->cookieFile) > 20) {
            try {
                $this->touchStore();
                $probe = $this->storeJson([
                    'comm' => 'getproductslist',
                    'projectid' => $this->projectId,
                    'slicesize' => '1',
                    'slice' => '1',
                ]);
                if (isset($probe['products']) || isset($probe['total'])) {
                    return;
                }
            } catch (Throwable $e) {
                // сессия истекла
            }
        }
        $this->login();
    }

    private function storeForm(array $fields): string
    {
        return $this->request('POST', 'https://store.tilda.ru/store/submit/', http_build_query($fields), [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://store.tilda.ru',
            'Referer: https://store.tilda.ru/store/?projectid=' . rawurlencode($this->projectId),
        ]);
    }

    private function storeJson(array $fields): array
    {
        $raw = trim($this->storeForm($fields));
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('Tilda store JSON: ' . substr($raw, 0, 300));
        }
        return $json;
    }

    private function login(): void
    {
        $html = $this->request('GET', 'https://tilda.ru/login/');
        $csrf = '';
        if (preg_match('/window\.csrf\s*=\s*[\'"]([^\'"]+)/', $html, $m)) {
            $csrf = $m[1];
        } elseif (preg_match('/name=["\']csrf["\'][^>]*value=["\']([^"\']+)/i', $html, $m)) {
            $csrf = $m[1];
        }

        $post = http_build_query([
            'email'    => $this->email,
            'password' => $this->password,
            'csrf'     => $csrf,
        ]);
        $resp = $this->request('POST', 'https://tilda.ru/login/submit/', $post, [
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: https://tilda.ru/login/',
            'Origin: https://tilda.ru',
        ]);

        $low = mb_strtolower($resp . ' ' . $this->lastHeaders);
        if (stripos($low, 'captcha') !== false) {
            throw new RuntimeException('Тильда запросила капчу при входе. Повторите позже или войдите в кабинет вручную.');
        }
        if (stripos($resp, 'error') !== false && stripos($resp, 'projects') === false) {
            $json = json_decode($resp, true);
            if (is_array($json) && !empty($json['error'])) {
                throw new RuntimeException('Тильда login: ' . $json['error']);
            }
        }
    }

    private string $lastHeaders = '';
    private int $lastHttpCode = 0;

    private function touchStore(): void
    {
        $this->request('GET', 'https://store.tilda.ru/store/?projectid=' . rawurlencode($this->projectId));
    }

    private function multipart(string $boundary, array $fields, string $filename, string $fileBody): string
    {
        $out = '';
        foreach ($fields as $name => $value) {
            $out .= "--{$boundary}\r\n";
            $out .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $out .= $value . "\r\n";
        }
        $out .= "--{$boundary}\r\n";
        $out .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $out .= "Content-Type: application/octet-stream\r\n\r\n";
        $out .= $fileBody . "\r\n";
        $out .= "--{$boundary}--\r\n";
        return $out;
    }

    private function request(string $method, string $url, ?string $body = null, array $headers = []): string
    {
        $ch = curl_init($url);
        $hdrs = array_merge([
            'Accept: text/html,application/json,*/*',
            'User-Agent: ' . $this->userAgent,
        ], $headers);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastHttpCode = $code;
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('cURL Tilda store: ' . $err);
        }
        $this->lastHeaders = substr($raw, 0, $headerSize);
        $resp = substr($raw, $headerSize);
        if ($code >= 500) {
            throw new RuntimeException("Tilda HTTP {$code}");
        }
        return (string)$resp;
    }
}
