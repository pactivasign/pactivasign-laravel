<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PactivaSign\Client;
use PactivaSign\Laravel\Testing\PactivaSignFake;

/**
 * @method static \PactivaSign\Resource\Envelopes envelopes()
 * @method static \PactivaSign\Resource\Documents documents()
 * @method static \PactivaSign\Resource\SigningRequests signingRequests()
 * @method static \PactivaSign\Resource\Templates templates()
 * @method static \PactivaSign\Resource\WebhookSubscriptions webhookSubscriptions()
 * @method static \PactivaSign\Resource\WebhookDeliveries webhookDeliveries()
 * @method static \PactivaSign\Resource\Me me()
 * @method static \PactivaSign\Http\Response request(string $method, string $path, array<string, mixed> $options = [])
 * @method static string authenticate()
 * @method static void forgetToken()
 *
 * @see \PactivaSign\Client
 */
final class PactivaSign extends Facade
{
    /**
     * Replaces the client with a fake that records requests and answers from `$stubs`.
     *
     *     PactivaSign::fake(['POST /api/v1/envelopes' => $envelopeJson, 'GET /api/v1/envelopes/*' => fn ($request) => [...]]);
     *
     * @param array<string, mixed> $stubs "METHOD /path" (with * wildcards) => data, a response or a callable
     */
    public static function fake(array $stubs = []): PactivaSignFake
    {
        $fake = new PactivaSignFake($stubs);
        static::swap($fake->client());
        static::$app?->instance(PactivaSignFake::class, $fake);

        return $fake;
    }

    /**
     * The client's resources are properties (`$client->envelopes`); the facade exposes them as methods.
     *
     * @param array<mixed> $arguments
     */
    public static function __callStatic($method, $arguments): mixed
    {
        $client = static::getFacadeRoot();
        if ($client instanceof Client && $arguments === [] && property_exists($client, $method)) {
            return $client->{$method};
        }

        return parent::__callStatic($method, $arguments);
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
