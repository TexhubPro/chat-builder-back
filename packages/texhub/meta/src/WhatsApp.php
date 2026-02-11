<?php

namespace TexHub\Meta;

class WhatsApp
{
    public function __construct(private array $config = [])
    {
    }

    public function config(): array
    {
        return $this->config;
    }
}
