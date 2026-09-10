<?php

declare(strict_types=1);

$env = static fn (string $key, string $default): string => getenv($key) !== false ? (string) getenv($key) : $default;

return [
    'app_name' => 'BlueMarket CMS',
    'app_mode' => $env('APP_MODE', 'vulnerable'),
    'pg' => [
        'host' => $env('PG_HOST', 'postgres'),
        'port' => $env('PG_PORT', '5432'),
        'db' => $env('PG_DB', 'bluemarket'),
        'users' => [
            'app' => [
                'user' => $env('APP_DB_USER', 'app_user'),
                'password' => $env('APP_DB_PASS', 'app_user_password'),
            ],
            'report' => [
                'user' => $env('REPORT_DB_USER', 'report_user'),
                'password' => $env('REPORT_DB_PASS', 'report_user_password'),
            ],
            'extension' => [
                'user' => $env('EXT_DB_USER', 'extension_user'),
                'password' => $env('EXT_DB_PASS', 'extension_user_password'),
            ],
        ],
    ],
    'paths' => [
        'storage' => dirname(__DIR__) . '/storage',
        'sqlite' => dirname(__DIR__) . '/storage/sqlite/catalog_cache.db',
        'templates' => dirname(__DIR__) . '/storage/templates',
        'uploads' => dirname(__DIR__) . '/public/uploads',
    ],
];
