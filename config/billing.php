<?php

return [
    'default_plan_code' => env('BILLING_DEFAULT_PLAN_CODE', 'starter-monthly'),
    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'TJS'),
    'alif' => [
        'mode' => env('BILLING_ALIF_MODE', 'local'),
        'callback_url' => env('BILLING_ALIF_CALLBACK_URL', rtrim((string) env('APP_URL'), '/') . '/api/billing/alif/callback'),
        'return_url' => env('BILLING_ALIF_RETURN_URL'),
        'test_checkout_url' => env('ALIFBANK_TEST_BASE_URL', 'https://test-web.alif.tj/'),
        'production_checkout_url' => env('ALIFBANK_BASE_URL', 'https://web.alif.tj/'),
    ],
];
