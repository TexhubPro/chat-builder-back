<?php

return [
    'assistant' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORG'),
        'project' => env('OPENAI_PROJECT'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'beta' => env('OPENAI_BETA_HEADER', 'assistants=v2'),
        'defaults' => [
            'model' => env('OPENAI_ASSISTANT_MODEL', 'gpt-4o'),
            'temperature' => env('OPENAI_ASSISTANT_TEMPERATURE', 1),
            'top_p' => env('OPENAI_ASSISTANT_TOP_P', 1),
            'response_format' => env('OPENAI_ASSISTANT_RESPONSE_FORMAT', 'auto'),
        ],
        'base_instructions' => env('OPENAI_ASSISTANT_BASE_INSTRUCTIONS'),
        'base_limits' => env('OPENAI_ASSISTANT_BASE_LIMITS'),
    ],
    'tts' => [
        'model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'voice' => env('OPENAI_TTS_VOICE', 'alloy'),
        'response_format' => env('OPENAI_TTS_FORMAT', 'mp3'),
        'speed' => env('OPENAI_TTS_SPEED', 1),
    ],
];
