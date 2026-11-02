<?php

declare(strict_types=1);

namespace Modules\Ordering\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redis fast path on the `state` store (ADR 0002): (user_id, key) -> request_hash + completed response.
 * It is a cache of the idempotency_keys row, written only after commit; a failed write or read is
 * ignored because the database is the truth. Never consulted for anything but completed responses.
 */
final class IdempotencyCache
{
    /** @return array{request_hash: string, status: int, body: array<string, mixed>}|null */
    public function get(string $userId, string $key): ?array
    {
        try {
            $value = Cache::store('state')->get(self::key($userId, $key));
        } catch (Throwable $error) {
            Log::channel('app')->warning('idempotency cache read failed; falling back to the database', ['error' => $error->getMessage()]);

            return null;
        }
        if (! is_array($value) || ! isset($value['request_hash'], $value['status'], $value['body']) || ! is_array($value['body'])) {
            return null;
        }

        return ['request_hash' => (string) $value['request_hash'], 'status' => (int) $value['status'], 'body' => $value['body']];
    }

    /** @param array<string, mixed> $body */
    public function put(string $userId, string $key, string $requestHash, int $status, array $body): void
    {
        try {
            Cache::store('state')->put(
                self::key($userId, $key),
                ['request_hash' => $requestHash, 'status' => $status, 'body' => $body],
                max(60, (int) config('ordering.idempotency_cache_ttl_seconds')),
            );
        } catch (Throwable $error) {
            Log::channel('app')->warning('idempotency cache write failed; database row remains authoritative', ['error' => $error->getMessage()]);
        }
    }

    private static function key(string $userId, string $key): string
    {
        return 'ordering:idempotency:'.$userId.':'.$key;
    }
}
