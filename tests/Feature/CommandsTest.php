<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PactivaSign\Key\LocalKey;
use PactivaSign\Laravel\Events\RecipientViewed;
use PactivaSign\Laravel\Facades\PactivaSign;
use PactivaSign\Laravel\Tests\TestCase;

final class CommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pactivasign-cmd-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testKeysWritesTwoPrivateKeysAndPrintsThePublicOne(): void
    {
        $this->artisan('pactivasign:keys', ['--dir' => $this->dir, '--kid' => 'acme-2026'])
            ->expectsOutputToContain('"kid": "acme-2026"')
            ->assertSuccessful();

        foreach (['client.jwk', 'dpop.jwk'] as $file) {
            self::assertSame('0600', substr(sprintf('%o', fileperms("$this->dir/$file")), -4));
        }
        $client = LocalKey::fromFile("$this->dir/client.jwk");
        self::assertSame('acme-2026', $client->keyId());
        self::assertNotSame($client->thumbprint(), LocalKey::fromFile("$this->dir/dpop.jwk")->thumbprint());
    }

    public function testKeysRefusesToOverwriteWithoutForce(): void
    {
        $this->artisan('pactivasign:keys', ['--dir' => $this->dir])->assertSuccessful();
        $before = file_get_contents("$this->dir/client.jwk");

        $this->artisan('pactivasign:keys', ['--dir' => $this->dir])->expectsOutputToContain('already exists')->assertFailed();
        self::assertSame($before, file_get_contents("$this->dir/client.jwk"));

        $this->artisan('pactivasign:keys', ['--dir' => $this->dir, '--force' => true])->assertSuccessful();
        self::assertNotSame($before, file_get_contents("$this->dir/client.jwk"));
    }

    public function testTestShowsTheConnection(): void
    {
        $me = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/me.json'), true);
        PactivaSign::fake(['GET /api/v1/me' => $me]);

        $this->artisan('pactivasign:test')
            ->expectsOutputToContain('Connected to PactivaSign.')
            ->assertSuccessful();
    }

    public function testTestReportsFailures(): void
    {
        PactivaSign::fake(['GET /api/v1/me' => \PactivaSign\Laravel\Testing\PactivaSignFake::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401)]);

        $this->artisan('pactivasign:test')
            ->expectsOutputToContain('Client authentication failed.')
            ->assertFailed();
    }

    public function testTestExplainsMissingCacheTables(): void
    {
        config(['pactivasign.cache_store' => 'database']);

        $this->artisan('pactivasign:test')
            ->expectsOutputToContain('"database" cache store cannot take locks')
            ->expectsOutputToContain('php artisan cache:table')
            ->expectsOutputToContain('PACTIVASIGN_CACHE_STORE=file')
            ->assertFailed();
    }

    public function testTestExplainsAMissingLockTableNextToAnExistingCacheTable(): void
    {
        config(['pactivasign.cache_store' => 'database']);
        $this->createCacheTable('cache');

        $this->artisan('pactivasign:test')
            ->expectsOutputToContain('cache:table will not add it')
            ->expectsOutputToContain('php artisan make:migration create_cache_locks_table')
            ->expectsOutputToContain("Schema::create('cache_locks'")
            ->assertFailed();
    }

    public function testTestConnectsWhenTheDatabaseStoreCanLock(): void
    {
        config(['pactivasign.cache_store' => 'database']);
        $this->createCacheTable('cache');
        $this->createCacheTable('cache_locks');
        $me = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/me.json'), true);
        PactivaSign::fake(['GET /api/v1/me' => $me]);

        $this->artisan('pactivasign:test')
            ->expectsOutputToContain('Connected to PactivaSign.')
            ->assertSuccessful();
    }

    public function testWebhookTestExplainsAMissingSecret(): void
    {
        config(['pactivasign.webhooks.secret' => null]);

        $this->artisan('pactivasign:webhook-test')
            ->expectsOutputToContain('PACTIVASIGN_WEBHOOK_SECRET')
            ->assertFailed();
    }

    public function testWebhookTestSendsASignedEventThroughTheRoute(): void
    {
        Event::fake([RecipientViewed::class]);

        $this->artisan('pactivasign:webhook-test', ['--type' => 'recipient.viewed'])
            ->expectsOutputToContain('-> 200')
            ->assertSuccessful();

        Event::assertDispatched(RecipientViewed::class);
    }

    /** The tables from Laravel's cache:table migration. */
    private function createCacheTable(string $name): void
    {
        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->string('key')->primary();
            if ($name === 'cache') {
                $table->mediumText('value');
            } else {
                $table->string('owner');
            }
            $table->integer('expiration');
        });
    }
}
