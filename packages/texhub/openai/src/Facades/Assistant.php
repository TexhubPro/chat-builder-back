<?php

namespace TexHub\OpenAi\Facades;

use Illuminate\Support\Facades\Facade;

class Assistant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'texhub.openai.assistant';
    }
}
