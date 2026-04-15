<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter([
        // env('FRONTEND_ORIGIN', 'http://localhost:3000'),
        'https://netsanya.connectinskillz.com',
        'https://www.netsanya.connectinskillz.com',
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
