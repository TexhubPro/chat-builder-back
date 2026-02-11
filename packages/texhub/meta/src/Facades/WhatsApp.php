<?php

namespace TexHub\Meta\Facades;

use Illuminate\Support\Facades\Facade;

class WhatsApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'texhub.meta.whatsapp';
    }
}
