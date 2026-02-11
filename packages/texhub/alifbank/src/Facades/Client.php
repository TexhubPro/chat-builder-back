<?php

namespace TexHub\AlifBank\Facades;

use Illuminate\Support\Facades\Facade;

class Client extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'texhub.alifbank.client';
    }
}
