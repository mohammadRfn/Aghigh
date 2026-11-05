<?php

declare(strict_types=1);

namespace Modules\Payment\Gateways;

use Closure;
use Illuminate\Support\Facades\Cache;
use Modules\Payment\Exceptions\GatewayTimeout;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Simulated provider for tests and load tests (no network).
 * - latency: uniform in [latency_min_ms, latency_max_ms]; failure_rate = declines; timeout_rate = GatewayTimeout
 *   after timeout_ms, half of them AFTER capturing (the ambiguous case idempotency keys exist for).
 * - PAYMENT_FAKE_SEED: every decision comes from a Randomizer seeded with sha256(seed|operation|key|attempt),
 *   so a seeded run makes the same decisions for the same orders whatever the worker interleaving.
 *   Empty seed = fresh randomness per call.
 * - Magic amounts (exact amount_minor, deterministic, for tests): 402 declines, 504 always times out,
 *   408 times out after capturing on attempt 1 and replays the capture afterwards.
 * - Idempotency: the first answer per key is kept in the state store (SETNX, like an external ledger);
 *   a repeated key returns it and never captures twice.
 */
final class FakeGateway implements PaymentGateway
{
    public const DECLINE_AMOUNT = 402;

    public const TIMEOUT_AMOUNT = 504;

    public const TIMEOUT_AFTER_CAPTURE_AMOUNT = 408;

    /** @param array<string, mixed> $config payment.fake */
    public function __construct(private readonly string $ledgerStore, private readonly array $config) {}

    public function charge(GatewayRequest $request): GatewayResult
    {
        return $this->operate('charge', $request, fn (Randomizer $rng): string => match (true) {
            $request->amountMinor === self::DECLINE_AMOUNT => 'decline',
            $request->amountMinor === self::TIMEOUT_AMOUNT => 'timeout',
            $request->amountMinor === self::TIMEOUT_AFTER_CAPTURE_AMOUNT => $request->attempt <= 1 ? 'timeout_after_capture' : 'approve',
            default => $this->randomTimeout($rng) ?? ($rng->nextFloat() < $this->rate('failure_rate') ? 'decline' : 'approve'),
        });
    }

    public function refund(GatewayRequest $request): GatewayResult
    {
        return $this->operate('refund', $request, fn (Randomizer $rng): string => $this->randomTimeout($rng) ?? 'approve');
    }

    public function find(string $idempotencyKey): ?GatewayResult
    {
        return $this->stored('charge', $idempotencyKey);
    }

    /** @param Closure(Randomizer): string $decide */
    private function operate(string $operation, GatewayRequest $request, Closure $decide): GatewayResult
    {
        $rng = $this->rng($operation, $request);
        $latency = $this->latency($rng);
        $stored = $this->stored($operation, $request->idempotencyKey);
        if ($stored !== null) {
            $this->pause($latency);

            return $stored;
        }

        $decision = $decide($rng);
        if ($decision === 'timeout' || $decision === 'timeout_after_capture') {
            if ($decision === 'timeout_after_capture') {
                $this->remember($operation, $request, GatewayResult::approved($this->reference($operation, $request)));
            }
            $this->pause((int) ($this->config['timeout_ms'] ?? 0));
            throw new GatewayTimeout('The payment gateway did not answer in time.');
        }

        $this->pause($latency);
        $result = $decision === 'decline'
            ? GatewayResult::declined('card_declined')
            : GatewayResult::approved($this->reference($operation, $request));

        return $this->remember($operation, $request, $result);
    }

    private function randomTimeout(Randomizer $rng): ?string
    {
        if ($rng->nextFloat() >= $this->rate('timeout_rate')) {
            return null;
        }

        return $rng->nextFloat() < 0.5 ? 'timeout' : 'timeout_after_capture';
    }

    private function rng(string $operation, GatewayRequest $request): Randomizer
    {
        $seed = (string) ($this->config['seed'] ?? '');
        if ($seed === '') {
            return new Randomizer(new Xoshiro256StarStar);
        }

        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed.'|'.$operation.'|'.$request->idempotencyKey.'|'.$request->attempt, true)));
    }

    private function latency(Randomizer $rng): int
    {
        $min = max(0, (int) ($this->config['latency_min_ms'] ?? 0));
        $max = max($min, (int) ($this->config['latency_max_ms'] ?? 0));

        return $max === 0 ? 0 : $rng->getInt($min, $max);
    }

    private function rate(string $key): float
    {
        return min(1.0, max(0.0, (float) ($this->config[$key] ?? 0.0)));
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function reference(string $operation, GatewayRequest $request): string
    {
        return 'fake_'.($operation === 'charge' ? 'ch' : 're').'_'.substr(hash('sha256', $operation.'|'.$request->idempotencyKey), 0, 24);
    }

    private function key(string $operation, string $idempotencyKey): string
    {
        return 'payment:fake:'.$operation.':'.$idempotencyKey;
    }

    private function stored(string $operation, string $idempotencyKey): ?GatewayResult
    {
        $value = Cache::store($this->ledgerStore)->get($this->key($operation, $idempotencyKey));

        return is_array($value) ? GatewayResult::fromArray($value) : null;
    }

    private function remember(string $operation, GatewayRequest $request, GatewayResult $result): GatewayResult
    {
        $ttl = max(60, (int) ($this->config['ledger_ttl_seconds'] ?? 604800));
        if (Cache::store($this->ledgerStore)->add($this->key($operation, $request->idempotencyKey), $result->toArray(), $ttl)) {
            return $result;
        }

        return $this->stored($operation, $request->idempotencyKey) ?? $result; // a concurrent call with the same key won
    }
}
