<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use PactivaSign\Key\LocalKey;
use PactivaSign\Laravel\PactivaSignServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const WEBHOOK_SECRET = 'whsec_test_current';

    protected function getPackageProviders($app): array
    {
        return [PactivaSignServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('pactivasign.client_id', 'pcl_laravel_test');
        $app['config']->set('pactivasign.base_url', 'https://api.pactiva.test');
        $app['config']->set('pactivasign.client_key.jwk', LocalKey::generate('ES256', 'k1')->exportPrivateJwk());
        $app['config']->set('pactivasign.dpop_key.jwk', LocalKey::generate('ES256')->exportPrivateJwk());
        $app['config']->set('pactivasign.webhooks.secret', self::WEBHOOK_SECRET);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (): void {
            \Illuminate\Support\Facades\Route::pactivaSignWebhooks();
        });
    }
}
