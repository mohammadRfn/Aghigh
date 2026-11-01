<?php

declare(strict_types=1);

namespace Modules\Catalog\Support;

use Illuminate\Support\Str;
use Modules\Catalog\Exceptions\CatalogProblem;
use Throwable;

final class CursorCodec
{
    /** @param array<string, mixed> $params */
    private function filterHash(array $params): string
    {
        unset($params['cursor']);
        ksort($params);

        return hash('sha256', json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new CatalogProblem('Catalog signing configuration is unavailable.', 503, 'catalog-unavailable');
        }

        return hash_hmac('sha256', 'aghigh/catalog/cursor/v1', $key, true);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{id:string,price_minor:int,offset:int}  $position
     */
    public function encode(array $params, array $position, string $mode = 'optimized'): string
    {
        $payload = json_encode(['v' => 1, 'sort' => $params['sort'], 'filters' => $this->filterHash($params), 'mode' => $mode, 'position' => $position], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, $this->key());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{id:string,price_minor:int,offset:int}|null
     */
    public function decode(array $params, string $mode = 'optimized'): ?array
    {
        if (empty($params['cursor'])) {
            return null;
        }
        $key = $this->key();
        try {
            $parts = explode('.', (string) $params['cursor']);
            if (count($parts) !== 2 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) || ! hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) {
                throw new \UnexpectedValueException;
            }
            $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);
            if ($raw === false) {
                throw new \UnexpectedValueException;
            }
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ($decoded['v'] ?? null) !== 1 || ($decoded['sort'] ?? null) !== $params['sort'] || ($decoded['filters'] ?? null) !== $this->filterHash($params) || ($decoded['mode'] ?? null) !== $mode) {
                throw new \UnexpectedValueException;
            }
            $position = $decoded['position'] ?? null;
            if (! is_array($position) || ! isset($position['id'], $position['price_minor'], $position['offset']) || ! is_string($position['id']) || ! Str::isUuid($position['id']) || ! is_int($position['price_minor']) || $position['price_minor'] < 1 || $position['price_minor'] > (int) config('catalog.price_max_minor') || ! is_int($position['offset']) || $position['offset'] < 0) {
                throw new \UnexpectedValueException;
            }

            return $position;
        } catch (Throwable) {
            throw new CatalogProblem('The cursor is malformed, tampered with, or belongs to different filters or sort.', 400, 'invalid-cursor');
        }
    }
}
