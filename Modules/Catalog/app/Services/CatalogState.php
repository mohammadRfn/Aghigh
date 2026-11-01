<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class CatalogState
{
    public static function store(): Repository
    {
        $repository = Cache::store('state');
        if (! $repository instanceof Repository) {
            throw new RuntimeException('State repository unavailable.');
        }

        return $repository;
    }

    public static function lock(string $key): Lock
    {
        $store = self::store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('State store must support atomic locks.');
        }

        return $store->lock($key, (int) config('catalog.cache_lock_seconds'));
    }
}
