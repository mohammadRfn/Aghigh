<?php

declare(strict_types=1);

namespace Modules\Ordering\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Contracts\ProductPricing;
use Modules\Inventory\Contracts\StockReservations;
use Modules\Ordering\DTOs\OrderPlacement;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Exceptions\OrderingProblem;
use Modules\Ordering\Http\Resources\OrderResource;
use Modules\Ordering\Models\Order;
use Modules\Ordering\Services\IdempotencyCache;
use Modules\Ordering\Services\OrderRateBudget;
use Modules\Ordering\Services\OrderSaga;
use Modules\Ordering\Services\OrderStateMachine;

/**
 * POST /orders in ONE transaction (ADR 0006):
 *   SET LOCAL lock_timeout (idempotency) -> INSERT idempotency row ON CONFLICT DO NOTHING
 *   -> SET LOCAL lock_timeout (normal) -> price items (ProductPricing, server-side)
 *   -> create order (pending) -> StockReservations::reserve -> transition to reserved (+ outbox OrderReserved)
 *   -> queue the payment saga (afterCommit: pushed only if this transaction commits, ADR 0007)
 *   -> complete the idempotency row with the response.
 * Any failure rolls everything back and stores nothing; a retry executes again.
 * Task 7: the business budget (orders per minute per user, OrderRateBudget) is consumed AFTER the idempotency
 * replay check and OUTSIDE the transaction: replays are free, rollbacks (409 out-of-stock) are not refunded.
 * A concurrent duplicate blocks on the unique (user_id, key) index; after the first commits it replays
 * the stored response, after a rollback it executes itself, after lock_timeout it gets 409 "idempotency-in-progress".
 */
final class PlaceOrder
{
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private readonly ProductPricing $pricing,
        private readonly StockReservations $stock,
        private readonly OrderStateMachine $machine,
        private readonly IdempotencyCache $cache,
        private readonly OrderSaga $saga,
        private readonly OrderRateBudget $budget,
    ) {}

    /** @param list<array{product_id: string, quantity: int}> $items */
    public function __invoke(string $userId, string $key, string $requestHash, array $items): OrderPlacement
    {
        $cached = $this->cache->get($userId, $key);
        if ($cached !== null) {
            self::assertSameRequest($cached['request_hash'], $requestHash);

            return new OrderPlacement($cached['status'], $cached['body'], true, isset($cached['body']['data']['id']) ? (string) $cached['body']['data']['id'] : null);
        }

        // Business limit, not the HTTP limiter: counted in Redis before (and independent of) the transaction.
        $this->budget->consume($userId);

        try {
            $placement = DB::transaction(fn (): OrderPlacement => $this->execute($userId, $key, $requestHash, $items));
        } catch (QueryException $error) {
            if (self::isLockTimeout($error)) {
                throw new OrderingProblem(
                    'Another request with this Idempotency-Key is still in progress; retry shortly.',
                    409,
                    'idempotency-in-progress',
                    [],
                    ['Retry-After' => '1'],
                );
            }
            throw $error;
        }

        if (! $placement->replayed) {
            $this->cache->put($userId, $key, $requestHash, $placement->status, $placement->body); // after commit; failures ignored
        }

        return $placement;
    }

    /** @param list<array{product_id: string, quantity: int}> $items */
    private function execute(string $userId, string $key, string $requestHash, array $items): OrderPlacement
    {
        $this->setLockTimeout((int) config('ordering.idempotency_lock_timeout_ms'));
        $now = CarbonImmutable::now();
        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'key' => $key, 'request_hash' => $requestHash,
            'status' => 'in_progress', 'created_at' => $now,
        ]);
        if ($inserted === 0) {
            return $this->replayFromDatabase($userId, $key, $requestHash);
        }
        $this->setLockTimeout((int) config('ordering.lock_timeout_ms'));

        [$lines, $total, $currency] = $this->price($items);
        $order = Order::query()->create([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'status' => OrderStatus::Pending, 'total_minor' => $total,
            'currency' => $currency, 'version' => 1, 'expires_at' => $now->addSeconds((int) config('ordering.reservation_ttl_seconds')),
        ]);
        DB::table('order_items')->insert(array_map(fn (array $line): array => ['id' => (string) Str::uuid7(), 'order_id' => $order->id] + $line, $lines));

        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line['product_id']] = $line['quantity'];
        }
        $this->stock->reserve($order->id, $quantities, $order->expires_at ?? $now); // throws 409 out-of-stock -> full rollback
        $this->machine->transition($order, OrderStatus::Reserved);               // conditional UPDATE + outbox OrderReserved
        $this->saga->start($order->id);                                          // Task 6: chain queued after COMMIT only

        $order->load('items');
        $body = ['data' => (new OrderResource($order))->resolve()];
        DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->update([
            'status' => 'completed', 'response_status' => 201, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
            'order_id' => $order->id, 'completed_at' => CarbonImmutable::now(),
        ]);

        return new OrderPlacement(201, $body, false, $order->id);
    }

    private function replayFromDatabase(string $userId, string $key, string $requestHash): OrderPlacement
    {
        $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();
        if ($row === null || (string) $row->status !== 'completed' || $row->response_body === null) {
            // Only "completed" rows are visible outside their transaction; anything else is a window we refuse to guess in.
            throw new OrderingProblem('Another request with this Idempotency-Key is still in progress; retry shortly.', 409, 'idempotency-in-progress', [], ['Retry-After' => '1']);
        }
        self::assertSameRequest((string) $row->request_hash, $requestHash);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $row->response_body, true, 32, JSON_THROW_ON_ERROR);

        return new OrderPlacement((int) $row->response_status, $body, true, $row->order_id === null ? null : (string) $row->order_id);
    }

    /**
     * Server-side prices from the ProductPricing contract; the client never supplies money.
     *
     * @param  list<array{product_id: string, quantity: int}>  $items
     * @return array{0: list<array{product_id: string, quantity: int, unit_price_minor: int, currency: string, line_total_minor: int}>, 1: int, 2: string}
     */
    private function price(array $items): array
    {
        $prices = $this->pricing->forProducts(array_map(fn (array $item): string => strtolower($item['product_id']), $items));
        $maxTotal = (int) config('ordering.max_total_minor');
        $errors = [];
        $lines = [];
        $total = 0;
        $currency = null;
        foreach ($items as $index => $item) {
            $productId = strtolower($item['product_id']);
            $price = $prices[$productId] ?? null;
            if ($price === null || ! $price->active) {
                $errors["items.{$index}.product_id"] = ['The product is unknown or not available for ordering.'];

                continue;
            }
            $currency ??= $price->currency;
            if ($price->currency !== $currency) {
                $errors["items.{$index}.product_id"] = ['All items of an order must share one currency.'];

                continue;
            }
            if ($price->price_minor > intdiv($maxTotal, $item['quantity'])) {
                $errors["items.{$index}.quantity"] = ['This line alone exceeds the maximum order total.'];

                continue;
            }
            $lineTotal = $price->price_minor * $item['quantity'];
            $total += $lineTotal;
            $lines[] = ['product_id' => $productId, 'quantity' => $item['quantity'], 'unit_price_minor' => $price->price_minor, 'currency' => $price->currency, 'line_total_minor' => $lineTotal];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($total > $maxTotal) {
            throw ValidationException::withMessages(['items' => ['The order total exceeds the maximum of '.$maxTotal.' minor units.']]);
        }

        return [$lines, $total, (string) $currency];
    }

    private static function assertSameRequest(string $stored, string $actual): void
    {
        if (! hash_equals($stored, $actual)) {
            throw new OrderingProblem('This Idempotency-Key was already used with a different request payload.', 422, 'idempotency-key-reuse');
        }
    }

    private function setLockTimeout(int $milliseconds): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // SET LOCAL lives and dies with this transaction: safe behind PgBouncer in transaction mode.
        DB::unprepared(sprintf('SET LOCAL lock_timeout = %d', max(0, $milliseconds)));
    }

    private static function isLockTimeout(QueryException $error): bool
    {
        $state = $error->errorInfo[0] ?? $error->getCode();

        return (string) $state === self::LOCK_NOT_AVAILABLE;
    }
}
