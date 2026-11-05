<?php

declare(strict_types=1);

namespace Modules\Shared\Queue\Middleware;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Laravel's WithoutOverlapping always locks on the DEFAULT cache store, which here is the LRU cache
 * Redis: a lock key could be evicted while the job runs. Same API (key, shared, releaseAfter,
 * expireAfter, dontRelease), but the lock lives on queue.locks.store (state Redis, noeviction; ADR 0002).
 */
final class WithoutOverlappingOnState extends WithoutOverlapping
{
    public function handle($job, $next)
    {
        $store = Cache::store(self::store())->getStore();
        if (! $store instanceof LockProvider) {
            throw new LogicException('queue.locks.store must support atomic locks.');
        }

        $lock = $store->lock($this->getLockKey($job), $this->expiresAfter);
        if ($lock->get()) {
            try {
                $next($job);
            } finally {
                $lock->release();
            }
        } elseif ($this->releaseAfter !== null) {
            $job->release($this->releaseAfter);
        }
    }

    public static function store(): string
    {
        return (string) config('queue.locks.store', 'state');
    }
}
