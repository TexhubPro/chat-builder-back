<?php

declare(strict_types=1);

/**
 * Shared-hosting fallback entrypoint.
 *
 * Use this when DocumentRoot is `back/` and cannot be changed to `back/public/`.
 */
require __DIR__.'/public/index.php';
