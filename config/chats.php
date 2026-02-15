<?php

return [
    'webhook_token' => env('CHAT_WEBHOOK_TOKEN'),
    'allowed_webhook_channels' => [
        'instagram',
        'telegram',
        'widget',
        'api',
    ],
    'widget' => [
        'auto_reply_enabled' => filter_var(env('WIDGET_AUTO_REPLY_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],
];
