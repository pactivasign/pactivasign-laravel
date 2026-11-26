<?php

declare(strict_types=1);

namespace PactivaSign\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PactivaSign\Client;
use PactivaSign\Environment;
use PactivaSign\Exception\ConfigurationException;
use PactivaSign\Laravel\Http\Controllers\WebhookController;
use PactivaSign\Laravel\Http\Middleware\VerifyWebhookSignature;
use Psr\Log\LoggerInterface;

final class PactivaSignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pactivasign.php', 'pactivasign');

        $this->app->singleton(Client::class, static function (Container $app): Client {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('pactivasign');
            $logger = $app->make(LoggerInterface::class);
            $store = $app->make(CacheFactory::class)->store(is_string($config['cache_store'] ?? null) ? $config['cache_store'] : null);
            $clientId = $config['client_id'] ?? null;
            if (!is_string($clientId) || $clientId === '') {
                throw new ConfigurationException('Set PACTIVASIGN_CLIENT_ID (pactivasign.client_id).');
            }
            /** @var list<string> $scopes */
            $scopes = is_array($config['scopes'] ?? null) ? array_values($config['scopes']) : [];

            return new Client(
                environment: self::environment($config),
                clientId: $clientId,
                clientKey: KeyResolver::resolve($app, self::keyConfig($config, 'client_key'), 'client_key', $logger),
                dpopKey: KeyResolver::resolve($app, self::keyConfig($config, 'dpop_key'), 'dpop_key', $logger),
                scopes: $scopes,
                cache: $store,
                tokenLock: new CacheTokenLock($store),
                logger: $logger,
            );
        });
        $this->app->alias(Client::class, 'pactivasign');
    }

    /**
     * PACTIVASIGN_ENVIRONMENT names the API; PACTIVASIGN_BASE_URL, when set, replaces it.
     *
     * @param array<string, mixed> $config
     */
    private static function environment(array $config): Environment
    {
        $name = strtolower(trim(is_string($config['environment'] ?? null) ? $config['environment'] : 'sandbox'));
        $named = match ($name) {
            'sandbox', '' => Environment::sandbox(),
            'production' => Environment::production(),
            default => throw new ConfigurationException(sprintf(
                'PACTIVASIGN_ENVIRONMENT (pactivasign.environment) must be "sandbox" or "production", not "%s".',
                $name,
            )),
        };
        $baseUrl = is_string($config['base_url'] ?? null) ? trim($config['base_url']) : '';
        if ($baseUrl === '') {
            return $named;
        }
        $custom = new Environment($baseUrl);
        // A config/pactivasign.php published before 0.2.3 defaults base_url to the sandbox, which would
        // quietly win over PACTIVASIGN_ENVIRONMENT=production.
        if ($name === 'production' && $custom->baseUrl !== $named->baseUrl) {
            throw new ConfigurationException(sprintf(
                'PACTIVASIGN_ENVIRONMENT is "production", but pactivasign.base_url is %s. Remove PACTIVASIGN_BASE_URL, '
                . 'or the base_url line from config/pactivasign.php if you published it.',
                $custom->baseUrl,
            ));
        }

        return $custom;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/pactivasign.php' => config_path('pactivasign.php')], 'pactivasign-config');
            $migrations = [__DIR__ . '/../database/migrations' => database_path('migrations')];
            if (method_exists($this, 'publishesMigrations')) { // @phpstan-ignore function.alreadyNarrowedType
                $this->publishesMigrations($migrations, 'pactivasign-migrations');
            } else {
                // Laravel 10 has no publishesMigrations(): the file keeps its own timestamp.
                $this->publishes($migrations, 'pactivasign-migrations');
            }
            $this->commands([
                Console\KeysCommand::class,
                Console\TestCommand::class,
                Console\WebhookTestCommand::class,
            ]);
        }

        $this->app->make(Router::class)->aliasMiddleware('pactivasign.webhook', VerifyWebhookSignature::class);

        /*
         * Route::pactivaSignWebhooks('pactivasign/webhook') registers the webhook endpoint, verified
         * and without CSRF protection. Put it in routes/web.php or routes/api.php.
         */
        Route::macro('pactivaSignWebhooks', function (string $uri = 'pactivasign/webhook'): RouteDefinition {
            return Route::post($uri, WebhookController::class)
                ->middleware('pactivasign.webhook')
                ->withoutMiddleware([
                    // Laravel 13 renamed CSRF protection; the older names are now its subclasses.
                    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
                    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                ])
                ->name('pactivasign.webhook');
        });
    }

    /**
     * @param array<string, mixed> $config
     * @return array{provider?: class-string<\PactivaSign\Key\KeyProvider>|null, jwk?: string|null, path?: string|null}
     */
    private static function keyConfig(array $config, string $name): array
    {
        $key = $config[$name] ?? [];

        /** @var array{provider?: class-string<\PactivaSign\Key\KeyProvider>|null, jwk?: string|null, path?: string|null} */
        return is_array($key) ? $key : [];
    }
}
