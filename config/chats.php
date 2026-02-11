<?php

return [
    'webhook_token' => env('CHAT_WEBHOOK_TOKEN'),
    'allowed_webhook_channels' => [
        'instagram',
        'telegram',
        'widget',
        'api',
    ],
];

