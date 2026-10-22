<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Tests\Integration;

use PactivaSign\Laravel\Facades\PactivaSign;
use PactivaSign\Laravel\Tests\TestCase;

/**
 * Runs against a real PactivaSign API with the same variables as the PHP SDK's integration tests
 * (PACTIVASIGN_BASE_URL, PACTIVASIGN_CLIENT_ID, PACTIVASIGN_CLIENT_KEY_FILE, PACTIVASIGN_DPOP_KEY_FILE,
 * PACTIVASIGN_SCOPES). Skipped when they are not set.
 */
final class LiveApiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if ((string) getenv('PACTIVASIGN_CLIENT_ID') === '') {
            return;
        }
        $app['config']->set('pactivasign.base_url', getenv('PACTIVASIGN_BASE_URL'));
        $app['config']->set('pactivasign.client_id', getenv('PACTIVASIGN_CLIENT_ID'));
        $app['config']->set('pactivasign.scopes', explode(' ', trim((string) getenv('PACTIVASIGN_SCOPES'), " \"'")));
        $app['config']->set('pactivasign.client_key', ['jwk' => null, 'path' => getenv('PACTIVASIGN_CLIENT_KEY_FILE')]);
        $app['config']->set('pactivasign.dpop_key', ['jwk' => null, 'path' => getenv('PACTIVASIGN_DPOP_KEY_FILE')]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if ((string) getenv('PACTIVASIGN_CLIENT_ID') === '') {
            self::markTestSkipped('PACTIVASIGN_CLIENT_ID is not set');
        }
    }

    public function testTheTestCommandConnects(): void
    {
        $this->artisan('pactivasign:test')
            ->expectsOutputToContain('Connected to PactivaSign.')
            ->assertSuccessful();
    }

    public function testTheFacadeReachesTheApi(): void
    {
        self::assertSame((string) getenv('PACTIVASIGN_CLIENT_ID'), PactivaSign::me()->get()->client?->clientId);
        self::assertNotNull(PactivaSign::envelopes()->list(['limit' => 1])->firstPage());
    }
}
