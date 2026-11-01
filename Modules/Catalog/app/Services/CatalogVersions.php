<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Illuminate\Support\Str;
use RuntimeException;

final class CatalogVersions
{
    public const DIRTY = 'catalog:versions:dirty';

    /** A fresh epoch prevents key resurrection if state was explicitly reset. */
    public function initialize(): void
    {
        $store = CatalogState::store();
        $keys = ['catalog:versions:epoch', 'catalog:versions:products', 'catalog:versions:categories', self::DIRTY];
        if (! in_array(null, $store->many($keys), true)) {
            return;
        }
        CatalogState::lock('catalog:versions:initialize')->block((int) config('catalog.cache_lock_wait_seconds'), function () use ($store, $keys): void {
            if (in_array(null, $store->many($keys), true)) {
                foreach ([$keys[0] => (string) Str::uuid7(), $keys[1] => 0, $keys[2] => 0, self::DIRTY => 0] as $key => $value) {
                    if (! $store->forever($key, $value)) {
                        throw new RuntimeException('Cannot initialize catalog version.');
                    }
                }
            }
        });
    }

    public function current(): string
    {
        $this->initialize();
        $store = CatalogState::store();
        if ((int) $store->get(self::DIRTY) > 0) {
            throw new RuntimeException('Catalog invalidation pending; bypass payload cache.');
        }
        $values = $store->many(['catalog:versions:epoch', 'catalog:versions:products', 'catalog:versions:categories']);
        if (in_array(null, $values, true)) {
            throw new RuntimeException('Catalog version reset in progress.');
        }

        return implode('.', $values);
    }

    /** Global invalidation: both counters advance after every successful admin write. */
    public function bump(): void
    {
        $this->initialize();
        foreach (['products', 'categories'] as $entity) {
            if (CatalogState::store()->increment('catalog:versions:'.$entity) === false) {
                throw new RuntimeException('Cannot invalidate catalog version.');
            }
        }
    }
}
