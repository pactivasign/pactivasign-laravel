<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Console;

use Illuminate\Console\Command;
use PactivaSign\Client;
use PactivaSign\Exception\ApiException;
use PactivaSign\Exception\PactivaException;

final class TestCommand extends Command
{
    protected $signature = 'pactivasign:test';

    protected $description = 'Check the PactivaSign credentials: get a token and show the organization, scopes and limits';

    public function handle(): int
    {
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
}
