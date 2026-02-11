<?php

namespace TexHub\AlifBank;

use Illuminate\Support\ServiceProvider;

class AlifBankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/alifbank.php', 'alifbank');

        $this->app->singleton('texhub.alifbank.client', function ($app) {
            return new Client($app['config']->get('alifbank', []));
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'texhub-alifbank');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/alifbank.php' => config_path('alifbank.php'),
            ], 'texhub-alifbank-config');
        }
    }
}
