<?php

return [
    'environment' => env('ALIFBANK_ENV', 'test'),
    'base_url' => env('ALIFBANK_BASE_URL', 'https://web.alif.tj/'),
    'test_base_url' => env('ALIFBANK_TEST_BASE_URL', 'https://test-web.alif.tj/'),
    'check_status_path' => env('ALIFBANK_CHECK_STATUS_PATH', '/checktxn'),
    'key' => env('ALIFBANK_KEY'),
    'password' => env('ALIFBANK_PASSWORD'),
    'gate' => env('ALIFBANK_GATE', 'km'),
    'callback_url' => env('ALIFBANK_CALLBACK_URL'),
    'return_url' => env('ALIFBANK_RETURN_URL'),
];
