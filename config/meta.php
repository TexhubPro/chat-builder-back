<?php

return [
    'instagram' => [
        'app_id' => env('META_INSTAGRAM_APP_ID'),
        'app_secret' => env('META_INSTAGRAM_APP_SECRET'),
        'verify_token' => trim((string) env('META_INSTAGRAM_VERIFY_TOKEN')) !== ''
            ? trim((string) env('META_INSTAGRAM_VERIFY_TOKEN'))
            : '1234567890',
        'redirect_path' => env('META_INSTAGRAM_REDIRECT_PATH', '/callback'),
        'redirect_uri' => env('META_INSTAGRAM_REDIRECT_URI'),
        'webhook_path' => env('META_INSTAGRAM_WEBHOOK_PATH', '/instagram-main-webhook'),
        'scopes' => env('META_INSTAGRAM_SCOPES', 'instagram_basic,instagram_manage_messages,pages_show_list,pages_messaging'),
        'graph_base' => env('META_INSTAGRAM_GRAPH_BASE', 'https://graph.instagram.com'),
        'api_version' => env('META_INSTAGRAM_API_VERSION', 'v23.0'),
        'auth_url' => env('META_INSTAGRAM_AUTH_URL'),
        'frontend_redirect_url' => env('META_INSTAGRAM_FRONTEND_REDIRECT_URL', 'http://localhost:5173/integrations'),
        'oauth_state_ttl_minutes' => env('META_INSTAGRAM_OAUTH_STATE_TTL_MINUTES', 15),
        'avatar_disk' => env('META_INSTAGRAM_AVATAR_DISK', 'public'),
        'avatar_dir' => env('META_INSTAGRAM_AVATAR_DIR', 'instagram/avatars'),
        'subscribe_after_connect' => env('META_INSTAGRAM_SUBSCRIBE_AFTER_CONNECT', true),
        'subscribed_fields' => env('META_INSTAGRAM_SUBSCRIBED_FIELDS', 'messages'),
        'token_refresh_grace_seconds' => env('META_INSTAGRAM_TOKEN_REFRESH_GRACE_SECONDS', 900),
        'auto_reply_enabled' => env('META_INSTAGRAM_AUTO_REPLY_ENABLED', true),
        'voice_reply_for_audio' => env('META_INSTAGRAM_VOICE_REPLY_FOR_AUDIO', true),
        'resolve_customer_profile' => env('META_INSTAGRAM_RESOLVE_CUSTOMER_PROFILE', true),
    ],
];
