<?php

return [
    'enabled' => filter_var(env('MODERATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
