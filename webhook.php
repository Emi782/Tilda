<?php
/**
 * Webhook-обработчик заказов Tilda -> Posiflora
 * 
 * Tilda отправляет POST application/x-www-form-urlencoded с полями:
 *   Name, Email, Phone, payment.products, payment.amount, payment.orderid
 *   formid, utm_source, utm_medium, utm_campaign и т.д.
 * 
 * Этот файл принимает webhook, создаёт заказ в Posiflora и отвечает "ok"
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

function logMessage(string $msg): void
{
    $file = __DIR__ . '/connector.log';
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/PosifloraClient.php', true);
    @opcache_invalidate(__DIR__ . '/TildaWebhookHandler.php', true);
    @opcache_invalidate(__DIR__ . '/config.php', true);
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/PosifloraClient.php';
require_once __DIR__ . '/TildaWebhookHandler.php';

$rawInput = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $data = json_decode($rawInput, true) ?? [];
} elseif (!empty($_POST)) {
    $data = $_POST;
} else {
    parse_str($rawInput, $data);
}

if (!is_array($data)) {
    $data = [];
}

logMessage('Webhook received: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

if (isset($_GET['health'])) {
    try {
        $api = new PosifloraClient($config['posiflora']);
        $api->login();
        $msg = 'Health ok, API ' . $api->getBaseUrl();
        logMessage($msg);
        echo $msg;
    } catch (Throwable $e) {
        logMessage('Health error: ' . $e->getMessage());
        echo 'fail: ' . $e->getMessage();
    }
    exit;
}

if (!empty($config['tilda']['webhook_secret'])) {
    $secret = $_SERVER['HTTP_X_TILDA_SECRET'] ?? ($data['secret'] ?? '');
    if ($secret !== $config['tilda']['webhook_secret']) {
        logMessage('Webhook: invalid secret');
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}

if (($data['test'] ?? '') === 'test' || empty($data)) {
    echo 'ok';
    exit;
}

try {
    $handler = new TildaWebhookHandler($config);
    $result = $handler->process($data);
    logMessage('Order created in Posiflora: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    echo 'ok';
} catch (Throwable $e) {
    logMessage('Webhook error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(200);
    echo 'ok';
}
