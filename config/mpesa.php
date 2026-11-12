<?php

return [
    'mpesa_env' => env('MPESA_ENV', 'sandbox'),

    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),

    'paybill' => env('MPESA_PAYBILL'),
    'till' => env('MPESA_TILL'),
    'store_number' => env('MPESA_STORE_NUMBER'),
    'passkey' => env('MPESA_PASSKEY'),

    'lnmo_callback_url' => env('MPESA_LNMO_CALLBACK'),
    'c2b_validate_callback' => env('MPESA_VALIDATE_CALLBACK'),
    'c2b_confirm_callback' => env('MPESA_CONFIRM_CALLBACK'),
    'pull_callback_url' => env('MPESA_PULL_CALLBACK'),

    'accounts' => [
        'paybill' => [
            'consumer_key' => env('MPESA_PAYBILL_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_PAYBILL_CONSUMER_SECRET'),
            'shortcode' => env('MPESA_PAYBILL'),
            'party_b' => env('MPESA_PAYBILL'),
            'passkey' => env('MPESA_PAYBILL_PASSKEY', env('MPESA_PASSKEY')),
            'callback_url' => env('MPESA_LNMO_CALLBACK'),
        ],

        'till' => [
            'consumer_key' => env('MPESA_TILL_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_TILL_CONSUMER_SECRET'),
            'shortcode' => env('MPESA_STORE_NUMBER'),
            'party_b' => env('MPESA_TILL'),
            'passkey' => env('MPESA_TILL_PASSKEY', env('MPESA_PASSKEY')),
            'callback_url' => env('MPESA_LNMO_CALLBACK'),
        ],
    ],

    'endpoint_versions' => [
        'stk_query' => 'v1',
        'c2b' => 'v2',
        'b2c' => 'v1',
    ],

    'http' => [
        'connect_timeout' => 5,
        'timeout' => 20,
    ],
];
