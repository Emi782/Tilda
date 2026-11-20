<?php
/**
 * Клиент CommerceML 2 для Тильды (без входа на tilda.ru и без капчи).
 *
 * Сессия: checkauth → init → file import0_1.xml → file offers0_1.xml
 *         → import import0_1.xml → import offers0_1.xml
 * Cookie из тела checkauth обязательна; тело file — содержимое XML, не multipart.
 */

class TildaCommerceMLClient
{
    private string $connectorUrl;
    private string $username;
    private string $password;
    private string $cookieFile;
    private string $sessionCookie = '';

    public function __construct(array $config)
    {
        $this->connectorUrl = rtrim($config['connector_url'] ?? '', '/');
        $this->username     = $config['username'] ?? '';
        $this->password     = $config['password'] ?? '';
        $this->cookieFile   = $config['cookie_file'] ?? sys_get_temp_dir() . '/tilda_cml_cookie.txt';

        if ($this->connectorUrl === '' || $this->username === '' || $this->password === '') {
            throw new RuntimeException('Не заданы connector_url / username / password Тильды (tilda_commerceml)');
        }
    }

    /**
     * @return array<string, string>
     */
    public function sync(string $importXmlPath, string $offersXmlPath): array
    {
        if (!is_file($importXmlPath) || !is_file($offersXmlPath)) {
            throw new RuntimeException('Не найдены import.xml / offers.xml');
        }
        $importXml = file_get_contents($importXmlPath);
        $offersXml = file_get_contents($offersXmlPath);
        if ($importXml === false || $importXml === '' || $offersXml === false || $offersXml === '') {
            throw new RuntimeException('import.xml / offers.xml пустые');
        }

        $log = [];
        $log['checkauth'] = $this->checkAuth();
        $log['init']      = $this->init();
        $log['file_import'] = $this->uploadFile('import0_1.xml', $importXml);
        $log['file_offers'] = $this->uploadFile('offers0_1.xml', $offersXml);
        $log['import'] = $this->importFile('import0_1.xml');
        $log['offers'] = $this->importFile('offers0_1.xml');
        return $log;
    }

    public function checkAuth(): string
    {
        $this->sessionCookie = '';
        $resp = $this->request('GET', ['type' => 'catalog', 'mode' => 'checkauth']);
        $lines = preg_split('/\r\n|\n|\r/', trim($resp)) ?: [];
        if (stripos($lines[0] ?? '', 'success') === false) {
            throw new RuntimeException('Tilda checkauth: ' . $resp);
        }
        $name  = trim($lines[1] ?? '');
        $value = trim($lines[2] ?? '');
        if ($name !== '' && $value !== '') {
            $this->sessionCookie = $name . '=' . $value;
        }
        return $resp;
    }

    public function init(): string
    {
        $resp = $this->request('GET', ['type' => 'catalog', 'mode' => 'init']);
        $head = $this->firstLine($resp);
        if ($head === 'failure' || $head === '') {
            throw new RuntimeException('Tilda init: ' . $resp);
        }
        return $resp;
    }

    public function uploadFile(string $filename, string $content): string
    {
        $resp = $this->request(
            'POST',
            ['type' => 'catalog', 'mode' => 'file', 'filename' => $filename],
            $content
        );
        if ($this->firstLine($resp) !== 'success') {
            throw new RuntimeException("Tilda file {$filename}: " . $resp);
        }
        return $resp;
    }

    public function importFile(string $filename): string
    {
        $deadline = time() + 300;
        $last = '';
        while (time() < $deadline) {
            $last = $this->request('GET', [
                'type'     => 'catalog',
                'mode'     => 'import',
                'filename' => $filename,
            ]);
            $head = $this->firstLine($last);
            if ($head === 'success') {
                return $last;
            }
            if ($head === 'progress') {
                sleep(3);
                continue;
            }
            throw new RuntimeException("Tilda import {$filename}: " . $last);
        }
        throw new RuntimeException("Tilda import timeout {$filename}: " . $last);
    }

    private function firstLine(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        return strtolower(trim($lines[0] ?? ''));
    }

    private function request(string $method, array $query, ?string $body = null): string
    {
        if ($this->sessionCookie !== '' && str_contains($this->sessionCookie, '=')) {
            [$sessName, $sessVal] = explode('=', $this->sessionCookie, 2);
            if ($sessName !== '' && $sessVal !== '' && !isset($query[$sessName])) {
                $query[$sessName] = $sessVal;
            }
        }
        $url = $this->connectorUrl . '?' . http_build_query($query);
        $headers = [
            'User-Agent: 1C+Enterprise/8.3',
            'Expect:',
        ];
        $isPost = strtoupper($method) === 'POST';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => ($isPost ? 60 : 180),
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];
        if (defined('CURL_REDIR_POST_ALL')) {
            $opts[CURLOPT_POSTREDIR] = CURL_REDIR_POST_ALL;
        }
        if ($this->sessionCookie !== '') {
            $headers[] = 'Cookie: ' . $this->sessionCookie;
        }
        if ($isPost) {
            $raw = $body ?? '';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $raw;
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'Content-Length: ' . strlen($raw);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        $lastError = '';
        $lastCode  = 0;
        $response  = '';
        $maxAttempts = strtoupper($method) === 'POST' ? 2 : 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $response  = curl_exec($ch);
            $lastError = curl_error($ch);
            $lastCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$lastError && $lastCode < 500 && $lastCode !== 0) {
                break;
            }
            sleep($attempt * 2);
        }

        if ($lastError) {
            throw new RuntimeException("cURL Tilda: {$lastError}");
        }
        if ($lastCode >= 400) {
            throw new RuntimeException("Tilda HTTP {$lastCode}: " . substr((string)$response, 0, 500));
        }
        return (string)$response;
    }
}
