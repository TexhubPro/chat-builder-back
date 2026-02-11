<?php

namespace TexHub\Meta\Facades;

use Illuminate\Support\Facades\Facade;

class Instagram extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'texhub.meta.instagram';
    }
}
