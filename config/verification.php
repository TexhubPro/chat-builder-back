<?php

return [
    'email_code' => [
        'expires_in_minutes' => env('EMAIL_VERIFICATION_EXPIRES_MINUTES', 10),
        'max_attempts' => env('EMAIL_VERIFICATION_MAX_ATTEMPTS', 4),
        'resend_cooldown_seconds' => env('EMAIL_VERIFICATION_RESEND_COOLDOWN_SECONDS', 60),
    ],
];
