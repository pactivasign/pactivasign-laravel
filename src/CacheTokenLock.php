<?php

declare(strict_types=1);

namespace PactivaSign\Laravel;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use PactivaSign\Auth\TokenLock;

/**
 * Token refresh lock on Laravel's atomic cache locks, so one worker renews the token while the
 * others wait for it. Stores without lock support fall back to letting each worker fetch its own.
 */
final class CacheTokenLock implements TokenLock
{
    /** @var array<string, Lock> */
    private array $held = [];

    public function __construct(private readonly Repository $cache)
    {
    }

    public function acquire(string $key, int $ttlSeconds): bool
    {
        $store = $this->cache->getStore();
        if (!$store instanceof LockProvider) {
            return true;
        }
        $lock = $store->lock($key, $ttlSeconds);
        if (!$lock->get()) {
            return false;
        }
        $this->held[$key] = $lock;

        return true;
    }

    public function release(string $key): void
    {
        if (isset($this->held[$key])) {
            $this->held[$key]->release();
            unset($this->held[$key]);
        }
    }
}
