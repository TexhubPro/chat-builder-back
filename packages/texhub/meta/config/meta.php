<?php

return [
    'instagram' => [
        'app_id' => env('META_INSTAGRAM_APP_ID'),
        'app_secret' => env('META_INSTAGRAM_APP_SECRET'),
        'verify_token' => env('META_INSTAGRAM_VERIFY_TOKEN', '1234567890'),
        'redirect_path' => env('META_INSTAGRAM_REDIRECT_PATH', '/callback'),
        'redirect_uri' => env('META_INSTAGRAM_REDIRECT_URI'),
        'webhook_path' => env('META_INSTAGRAM_WEBHOOK_PATH', '/instagram-main-webhook'),
        'scopes' => env('META_INSTAGRAM_SCOPES', 'instagram_basic,instagram_manage_messages,pages_show_list,pages_messaging'),
        'graph_base' => env('META_INSTAGRAM_GRAPH_BASE', 'https://graph.instagram.com'),
        'api_version' => env('META_INSTAGRAM_API_VERSION', 'v23.0'),
        'auth_url' => env('META_INSTAGRAM_AUTH_URL'),
        'token_refresh_grace_seconds' => env('META_INSTAGRAM_TOKEN_REFRESH_GRACE_SECONDS', 900),
        'profile_token_refresh_cooldown_minutes' => env('META_INSTAGRAM_PROFILE_TOKEN_REFRESH_COOLDOWN_MINUTES', 360),
        'auto_reply_enabled' => env('META_INSTAGRAM_AUTO_REPLY_ENABLED', true),
        'voice_reply_for_audio' => env('META_INSTAGRAM_VOICE_REPLY_FOR_AUDIO', true),
        'resolve_customer_profile' => env('META_INSTAGRAM_RESOLVE_CUSTOMER_PROFILE', true),
        'customer_profile_refresh_minutes' => env('META_INSTAGRAM_CUSTOMER_PROFILE_REFRESH_MINUTES', 1440),
    ],
];
