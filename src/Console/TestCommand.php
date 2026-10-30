<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Console;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Connection;
use PactivaSign\Client;
use PactivaSign\Exception\ApiException;
use PactivaSign\Exception\PactivaException;

final class TestCommand extends Command
{
    protected $signature = 'pactivasign:test';

    protected $description = 'Check the PactivaSign credentials: get a token and show the organization, scopes and limits';

    public function handle(): int
    {
        if (!$this->tokenLockWorks()) {
            return self::FAILURE;
        }

        try {
            $client = $this->laravel->make(Client::class);
            $me = $client->me->get();
        } catch (PactivaException $e) {
            $this->error('PactivaSign connection failed: ' . $e->getMessage());
            if ($e instanceof ApiException) {
                $this->line('Error code: ' . $e->errorCode);
            }

            return self::FAILURE;
        }

        $this->info('Connected to PactivaSign.');
        $this->table(['', ''], [
            ['API', $client->environment()->baseUrl],
            ['Environment', $me->environment],
            ['Organization', sprintf('%s (%s, %s)', $me->organization->name, $me->organization->slug, $me->organization->status->value)],
            ['Client', $me->client === null ? '-' : sprintf('%s (%s)', $me->client->name, $me->client->clientId)],
            ['Scopes', implode(' ', $me->scopes)],
            ['Plan', sprintf('%s, %s', $me->plan->name, $me->plan->subscriptionStatus->value)],
            ['Rate limits', $me->rateLimits === null ? '-' : sprintf('%s/min, %s expensive/min', $me->rateLimits->requestsPerMinute, $me->rateLimits->expensivePerMinute)],
        ]);

        return self::SUCCESS;
    }

    /**
     * The token refresh takes a lock in the cache store. A store that cannot lock fails on the first
     * API call with an error about the store, not PactivaSign, so check it here and say how to fix it.
     */
    private function tokenLockWorks(): bool
    {
        $config = $this->laravel->make('config');
        $name = $config->get('pactivasign.cache_store');
        $name = is_string($name) && $name !== '' ? $name : $config->get('cache.default');
        $name = is_string($name) ? $name : '';

        $store = null;
        try {
            $store = $this->laravel->make(CacheFactory::class)->store($name === '' ? null : $name)->getStore();
            if (!$store instanceof LockProvider) {
                return true;
            }
            $lock = $store->lock('pactivasign.lock-check', 5);
            if ($lock->get()) {
                $lock->release();
            }

            return true;
        } catch (\Throwable $e) {
            $this->error(sprintf('The "%s" cache store cannot take locks, which PactivaSign uses to refresh its token.', $name));
            $this->line($e->getMessage());
            $this->newLine();
            if ($store instanceof DatabaseStore) {
                $this->explainDatabaseLocks($store, $name);
            }
            $this->line('Or keep the PactivaSign token in a store that can lock: set PACTIVASIGN_CACHE_STORE=file (or redis) in .env.');

            return false;
        }
    }

    private function explainDatabaseLocks(DatabaseStore $store, string $name): void
    {
        $config = $this->laravel->make('config');
        $table = $config->get("cache.stores.$name.table");
        $lockTable = $config->get("cache.stores.$name.lock_table");
        $table = is_string($table) && $table !== '' ? $table : 'cache';
        $lockTable = is_string($lockTable) && $lockTable !== '' ? $lockTable : 'cache_locks';

        $connection = $store->getConnection();
        if ($connection instanceof Connection && !$connection->getSchemaBuilder()->hasTable($table)) {
            $this->line("Create the cache tables ($table and $lockTable):");
            $this->line('    php artisan cache:table');
            $this->line('    php artisan migrate');
            $this->newLine();

            return;
        }

        // cache:table (make:cache-table from Laravel 11) refuses to run once a create_cache_table migration exists.
        $this->line("The $table table exists but $lockTable does not, so php artisan cache:table will not add it.");
        $this->line("Create a migration for the lock table only (php artisan make:migration create_{$lockTable}_table) with:");
        $this->line("    Schema::create('$lockTable', function (Blueprint \$table) {");
        $this->line("        \$table->string('key')->primary();");
        $this->line("        \$table->string('owner');");
        $this->line("        \$table->integer('expiration');");
        $this->line('    });');
        $this->line('Then run php artisan migrate.');
        $this->newLine();
    }
}
