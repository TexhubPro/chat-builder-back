<?php

namespace TexHub\Meta;

use TexHub\Meta\Console\InstallMetaCommand;
use Illuminate\Support\ServiceProvider;

class MetaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/meta.php', 'meta');

        $this->app->singleton('texhub.meta.instagram', function ($app) {
            return new Instagram($app['config']->get('meta.instagram', []));
        });

        $this->app->singleton('texhub.meta.whatsapp', function ($app) {
            return new WhatsApp($app['config']->get('meta.whatsapp', []));
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'texhub-meta');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/meta.php' => config_path('meta.php'),
            ], 'texhub-meta-config');

            $this->commands([
                InstallMetaCommand::class,
            ]);
        }
    }
}
