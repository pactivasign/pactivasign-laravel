<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PactivaSign\Client;
use PactivaSign\Exception\ConfigurationException;
use PactivaSign\Exception\NotFoundException;
use PactivaSign\Laravel\CacheTokenLock;
use PactivaSign\Laravel\Facades\PactivaSign;
use PactivaSign\Laravel\Testing\PactivaSignFake;
use PactivaSign\Laravel\Testing\RecordedRequest;
use PactivaSign\Laravel\Tests\TestCase;
use PactivaSign\Key\LocalKey;
use PactivaSign\Model\Envelope;
use PHPUnit\Framework\AssertionFailedError;

final class ClientTest extends TestCase
{
    public function testTheClientIsBuiltFromConfig(): void
    {
        $client = $this->app->make(Client::class);

        self::assertSame('https://api.pactiva.test', $client->environment()->baseUrl);
        self::assertSame($client, $this->app->make('pactivasign'));
        self::assertSame($client, PactivaSign::getFacadeRoot());
    }

    public function testKeysCanComeFromFiles(): void
    {
        $dir = sys_get_temp_dir() . '/pactivasign-keys-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/client.jwk", LocalKey::generate('ES256', 'k')->exportPrivateJwk());
        file_put_contents("$dir/dpop.jwk", LocalKey::generate('ES256')->exportPrivateJwk());
        config([
            'pactivasign.client_key' => ['jwk' => null, 'path' => "$dir/client.jwk"],
            'pactivasign.dpop_key' => ['jwk' => null, 'path' => "$dir/dpop.jwk"],
        ]);
        $this->app->forgetInstance(Client::class);

        self::assertInstanceOf(Client::class, $this->app->make(Client::class));
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);
    }

    public function testAMissingKeyFileExplainsWhatToDo(): void
    {
        config(['pactivasign.client_key' => ['jwk' => null, 'path' => '/nonexistent/client.jwk']]);
        $this->app->forgetInstance(Client::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('pactivasign:keys');
        $this->app->make(Client::class);
    }

    public function testAMissingClientIdIsReported(): void
    {
        config(['pactivasign.client_id' => null]);
        $this->app->forgetInstance(Client::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PACTIVASIGN_CLIENT_ID');
        $this->app->make(Client::class);
    }

    public function testTheTokenLockUsesAtomicCacheLocks(): void
    {
        $lock = new CacheTokenLock(Cache::store('array'));

        self::assertTrue($lock->acquire('pactivasign.test.lock', 10));
        self::assertFalse((new CacheTokenLock(Cache::store('array')))->acquire('pactivasign.test.lock', 10));
        $lock->release('pactivasign.test.lock');
        self::assertTrue((new CacheTokenLock(Cache::store('array')))->acquire('pactivasign.test.lock', 10));
    }

    public function testTheFakeRecordsRequestsAndAnswersFromStubs(): void
    {
        $envelope = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/envelope.json'), true);
        $fake = PactivaSign::fake([
            'POST /api/v1/envelopes' => $envelope,
            'GET /api/v1/envelopes/missing' => PactivaSignFake::response(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'nope', 'requestId' => 'r']], 404),
        ]);

        $created = PactivaSign::envelopes()->create(['name' => 'NDA', 'signers' => [['email' => 'jo@example.com', 'name' => 'Jo']]]);

        self::assertInstanceOf(Envelope::class, $created);
        $fake->assertSent('POST /api/v1/envelopes');
        $fake->assertSent(fn (RecordedRequest $r) => $r->is('POST /api/v1/envelopes') && $r->json()['name'] === 'NDA' && $r->header('Idempotency-Key') !== '');
        $fake->assertNotSent('GET *');

        $this->expectException(NotFoundException::class);
        PactivaSign::envelopes()->get('missing');
    }

    public function testTheFakeFailsOnUnstubbedRequests(): void
    {
        PactivaSign::fake();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('no stub for GET /api/v1/me');
        PactivaSign::me()->get();
    }
}
