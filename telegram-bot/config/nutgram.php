<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),

    'config' => [
        'timeout' => 30,
        'polling' => [
            'timeout' => 10,
        ],
    ],

    'routes' => true,
];
