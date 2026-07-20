<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // 🚨 Izinkan origin dari localhost React lu
    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // 🚨 Wajib true biar tokennya bisa lewat
];