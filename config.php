<?php
/**
 * Конфигурация коннектора Posiflora <-> Tilda
 */

return [
    // Posiflora API
    'posiflora' => [
        'base_url'  => 'https://daribuzuluk.posiflora.com/api',
        'api_key'   => 'pf_38581_jwyxckg56pkpjqjhdcdyvlslsnkfjkdv4umzypmgqbz7d2j23e7a_3349295e',
        'username'  => 'Dygalkina',
        'password'  => '05052012',
        'store_id'  => 'f734d2c9-ecbd-4801-a7d3-99d444ba994a',     // ID магазина/склада в Posiflora — заполните после первого запуска
        'source_id' => '9bed7b59-94dc-4ce4-aac2-883aab1a148c',     // ID источника заказов "Tilda" — заполните после первого запуска
        'shop_url_path' => 'daribuzuluk',
    ],

    // Публичный адрес скриптов на хостинге. Тильда скачивает фото только отсюда.
    'public_base' => 'https://startergpt.ru/tilda',

    // Tilda webhook
    'tilda' => [
        'webhook_secret' => '',  // Если задан — проверяется заголовок или параметр
    ],

    // Коннектор CommerceML Тильды (логин/пароль из Товары → Синхронизация через CommerceML)
    'tilda_commerceml' => [
        'connector_url' => 'https://store.tilda.ru/connectors/commerceml/',
        'username'      => '45092109',
        'password'      => '1b2d9cc5c9e816f16893d3748cf5d7de',
        'cookie_file'   => __DIR__ . '/export/.tilda_cml_cookie.txt',
    ],

    // YML-заливка в каталог Тильды (для крона). Пароль — от входа на tilda.ru
    'tilda_store' => [
        'projectid'   => '45092109',
        'email'       => 'Spartakovna.98@mail.ru',
        'password'    => 'oj1PIGNr27!',
        'cookie_file' => __DIR__ . '/export/.tilda_store_cookie.txt',
    ],

    // Кэш токена (файл для хранения accessToken между запусками)
    'token_file' => __DIR__ . '/.posiflora_token.json',

    // Каталог для выгрузки CommerceML
    'export_dir' => __DIR__ . '/export',

    // Логи
    'log_file' => __DIR__ . '/connector.log',
];
