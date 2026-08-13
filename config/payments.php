<?php

return [
    'live_enabled' => (bool) env('PAYMENTS_LIVE_ENABLED', false),
    'fake' => (bool) env('PAYMENTS_FAKE_PROVIDER', false),
    'private_disk' => env('PRIVATE_UPLOAD_DISK', 'local'),
    'attempt_minutes' => 60,
    'connections' => [
        'square' => [
            'sandbox' => [
                'application_id' => env('SQUARE_SANDBOX_APPLICATION_ID', env('SQUARE_APPLICATION_ID')),
                'application_secret' => env('SQUARE_SANDBOX_APPLICATION_SECRET', env('SQUARE_APPLICATION_SECRET')),
                'webhook_signature_key' => env('SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY', env('SQUARE_WEBHOOK_SIGNATURE_KEY')),
            ],
            'production' => [
                'application_id' => env('SQUARE_PRODUCTION_APPLICATION_ID'),
                'application_secret' => env('SQUARE_PRODUCTION_APPLICATION_SECRET'),
                'webhook_signature_key' => env('SQUARE_PRODUCTION_WEBHOOK_SIGNATURE_KEY'),
            ],
        ],
        'stripe' => [
            'test' => [
                'client_id' => env('STRIPE_TEST_CONNECT_CLIENT_ID', env('STRIPE_CONNECT_CLIENT_ID')),
                'platform_secret' => env('STRIPE_TEST_PLATFORM_SECRET', env('STRIPE_PLATFORM_SECRET')),
                'webhook_secret' => env('STRIPE_TEST_CONNECT_WEBHOOK_SECRET', env('STRIPE_CONNECT_WEBHOOK_SECRET')),
            ],
            'live' => [
                'client_id' => env('STRIPE_LIVE_CONNECT_CLIENT_ID'),
                'platform_secret' => env('STRIPE_LIVE_PLATFORM_SECRET'),
                'webhook_secret' => env('STRIPE_LIVE_CONNECT_WEBHOOK_SECRET'),
            ],
        ],
    ],
];
