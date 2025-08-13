<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://main.d2teqaqu3kocnl.amplifyapp.com',
        'http://localhost:3000'
    ],
    'allowed_origins_patterns' => [
        '/^https:\/\/.*\.amplifyapp\.com$/', // 全てのAmplifyドメインを許可
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
