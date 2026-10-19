<?php

/**
 * Canyon Framework - Application Configuration
 */

return [
    'app' => [
        'name'      => 'Canyon Framework',
        'debug'     => env('APP_DEBUG', false),
        'url'       => env('APP_URL', 'http://localhost'),
        'timezone'  => env('TIMEZONE', 'UTC'),
    ],

    'database' => [
        'default' => env('DB_CONNECTION', 'mysql'),
        'connections' => [
            'mysql' => [
                'driver'   => 'mysql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'canyon'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
            ],
        ],
    ],
];
