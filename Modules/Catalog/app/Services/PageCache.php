<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Closure;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PageCache
{
    public function __construct(private readonly CatalogVersions $versions) {}

    /**
     * Only plain arrays enter the disposable store. Cold misses hold the state lock
     * through both computation and storage, and re-check after acquiring it.
     *
     * @param array<string, mixed> $params
     * @param Closure(): array<string, mixed> $compute
     * @return array<string, mixed>
     */
    public function remember(string $namespace, array $params, Closure $compute): array
    {
        if (! empty($params['q'])) {
            return $compute();
        }
        try {
            ksort($params);
            $key = 'catalog:'.$namespace.':v'.$this->versions->current().':'.hash('sha256', json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $cache = Cache::store('cache');
            $stored = $cache->many([$key, 'illuminate:cache:flexible:created:'.$key]);
            if (! in_array(null, $stored, true)) {
                return $this->flexible($key, $compute);
            }

            return CatalogState::lock('catalog:cold:'.hash('sha256', $key))
                ->block((int) config('catalog.cache_lock_wait_seconds'), fn (): array => $this->flexible($key, $compute));
        } catch (Throwable $error) {
            // Includes lock timeout: compute directly, WITHOUT a subsequent cache write.
            self::warning($error);

            return $compute();
        }
    }

    /** @param Closure(): array<string, mixed> $compute @return array<string, mixed> */
    private function flexible(string $key, Closure $compute): array
    {
        $result = Cache::store('cache')->flexible($key, [
            (int) config('catalog.cache_fresh_seconds'),
            (int) config('catalog.cache_stale_seconds'),
        ], $compute, ['seconds' => (int) config('catalog.cache_lock_seconds')]);

        // Laravel 13's native refresh uses the cache store's lock_connection=state.
        // Guard the *whole* deferred operation, including lock acquisition and putMany,
        // not only the database callback. Its failure must warn on the app channel.
        $callbacks = app(DeferredCallbackCollection::class);
        for ($index = 0; $index < count($callbacks); $index++) {
            $deferred = $callbacks[$index];
            if ($deferred->name === 'illuminate:cache:flexible:'.$key) {
                $original = $deferred->callback;
                $deferred->callback = static function () use ($original): void {
                    try {
                        $original();
                    } catch (Throwable $error) {
                        self::warning($error);
                    }
                };
            }
        }

        return $result;
    }

    public static function warning(Throwable $error): void
    {
        Log::channel('app')->warning('Catalog cache unavailable; serving database reads.', ['exception' => $error::class]);
    }
}
