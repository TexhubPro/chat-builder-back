<?php

namespace TexHub\OpenAi;

use Illuminate\Support\ServiceProvider;

class OpenAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openai.php', 'openai');

        $this->app->singleton(Assistant::class, function ($app) {
            return new Assistant($app['config']->get('openai.assistant', []));
        });

        $this->app->alias(Assistant::class, 'texhub.openai.assistant');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'texhub-openai');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/openai.php' => config_path('openai.php'),
            ], 'texhub-openai-config');
        }
    }
}
