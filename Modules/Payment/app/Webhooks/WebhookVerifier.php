<?php

declare(strict_types=1);

namespace Modules\Payment\Webhooks;

use Illuminate\Support\Facades\Cache;
use JsonException;
use Modules\Payment\Exceptions\WebhookRejected;

/**
 * X-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "{t}.{raw body}">[,v1=...]
 * - |now - t| <= tolerance (default 300 s), checked before any HMAC work;
 * - every v1 is compared with hash_equals against the current AND the previous secret (rotation),
 *   without early exit;
 * - replay: SETNX on the state store keyed by sha256("{t}.{body}"), TTL = tolerance + 60 s, so the
 *   same signed message cannot be accepted twice while its timestamp is still valid;
 * - then the payload must be a JSON object with id, type and data.
 * Any failure is the same WebhookRejected (401) whatever the reason.
 */
final class WebhookVerifier
{
    /**
     * @return array{event: array{id: string, type: string, data: array<string, mixed>}, signed_at: int, replay_key: string}
     */
    public function verify(string $header, string $body, int $now): array
    {
        $secrets = array_values(array_filter(
            [(string) config('payment.webhook.secret', ''), (string) config('payment.webhook.previous_secret', '')],
            fn (string $secret): bool => $secret !== '',
        ));
        if ($secrets === []) {
            throw WebhookRejected::because('not-configured');
        }
        if (strlen($body) > max(1024, (int) config('payment.webhook.max_bytes', 65536))) {
            throw WebhookRejected::because('too-large');
        }

        [$timestamp, $signatures] = self::parse($header);
        $tolerance = max(1, (int) config('payment.webhook.tolerance_seconds', 300));
        if (abs($now - $timestamp) > $tolerance) {
            throw WebhookRejected::because('timestamp-outside-tolerance');
        }

        $signed = $timestamp.'.'.$body;
        $valid = false;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $signed, $secret);
            foreach ($signatures as $signature) {
                $valid = hash_equals($expected, $signature) || $valid;
            }
        }
        if (! $valid) {
            throw WebhookRejected::because('bad-signature');
        }

        $replayKey = 'payment:webhook:replay:'.hash('sha256', $signed);
        if (! Cache::store((string) config('payment.webhook.replay_store', 'state'))->add($replayKey, 1, $tolerance + 60)) {
            throw WebhookRejected::because('replayed');
        }

        return ['event' => self::event($body), 'signed_at' => $timestamp, 'replay_key' => $replayKey];
    }

    public function forgetReplay(string $replayKey): void
    {
        Cache::store((string) config('payment.webhook.replay_store', 'state'))->forget($replayKey);
    }

    /** @return array{0: int, 1: list<string>} */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/', $value) === 1 && count($signatures) < 5) {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw WebhookRejected::because('malformed-header');
        }

        return [$timestamp, $signatures];
    }

    /** @return array{id: string, type: string, data: array<string, mixed>} */
    private static function event(string $body): array
    {
        try {
            $event = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw WebhookRejected::because('malformed-payload');
        }
        if (! is_array($event)
            || ! is_string($event['id'] ?? null) || preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $event['id']) !== 1
            || ! is_string($event['type'] ?? null) || preg_match('/^[a-z0-9_.]{1,64}$/', $event['type']) !== 1
            || ! is_array($event['data'] ?? null)) {
            throw WebhookRejected::because('malformed-payload');
        }

        /** @var array<string, mixed> $data */
        $data = $event['data'];

        return ['id' => $event['id'], 'type' => $event['type'], 'data' => $data];
    }
}
