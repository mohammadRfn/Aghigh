<?php

declare(strict_types=1);

// Task 7: only the Shared kernel touches the limiter Redis keys and Lua. Feature modules use the contracts.

arch('only Shared\RateLimiting owns limiter Lua scripts and raw limiter keys')
    ->expect('Modules\Shared\RateLimiting\LuaScripts')
    ->toOnlyBeUsedIn(['Modules\Shared\RateLimiting']);

arch('limiter backends are not used directly by feature modules')
    ->expect([
        'Modules\Shared\RateLimiting\RedisSlidingWindowLimiter',
        'Modules\Shared\RateLimiting\RedisConcurrencyLimiter',
        'Modules\Shared\RateLimiting\InMemorySlidingWindowLimiter',
        'Modules\Shared\RateLimiting\InMemoryConcurrencyLimiter',
    ])
    ->toOnlyBeUsedIn(['Modules\Shared\Providers', 'Modules\Shared\RateLimiting']);

arch('feature modules never talk to a limiter engine directly (only through throttle.sliding / the contracts)')
    ->expect(['Modules\Identity', 'Modules\Catalog', 'Modules\Inventory', 'Modules\Ordering', 'Modules\Payment', 'Modules\Chat', 'Modules\Notification', 'App'])
    ->not->toUse(['Illuminate\Support\Facades\RateLimiter', 'Illuminate\Cache\RateLimiter', 'Illuminate\Cache\RateLimiting\Limit']);

// Client IP comes from Request::ip() through TrustProxies only: nobody reads the forwarded header by hand.
test('nothing reads X-Forwarded-For by hand', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    $directories = [...glob($root.'/Modules/*/app', GLOB_ONLYDIR) ?: [], ...glob($root.'/Modules/*/routes', GLOB_ONLYDIR) ?: [], $root.'/app'];
    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/header\(\s*[\'"]x-forwarded-for|HTTP_X_FORWARDED_FOR|getClientIps\(/i', $code) === 1) {
                $offenders[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }
    expect($offenders)->toBe([]);
});
