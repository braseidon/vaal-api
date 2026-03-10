<?php

namespace Braseidon\VaalApi\Laravel;

use Braseidon\VaalApi\Client\ApiClient;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the Vaal API package.
 *
 * Registers the ApiClient as a singleton and publishes
 * the configuration file.
 */
class VaalApiServiceProvider extends ServiceProvider
{
    /**
     * Register the ApiClient singleton and merge config.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/vaal-api.php',
            'vaal-api',
        );

        $this->app->singleton(ApiClient::class, function ($app) {
            return new ApiClient($app['config']['vaal-api'] ?? []);
        });
    }

    /**
     * Publish the configuration file.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/vaal-api.php' => config_path('vaal-api.php'),
            ], 'vaal-api-config');
        }
    }
}
