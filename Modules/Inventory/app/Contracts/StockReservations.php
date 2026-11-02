<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use DateTimeInterface;

/**
 * The only Inventory surface other modules may call. Ordering reserves inside its own order
 * transaction; release/commit/releaseExpired open their own short transactions when none is active.
 * Inventory never imports Ordering: orders are referenced by id only (ADR 0001).
 */
interface StockReservations
{
    /**
     * Reserve stock for an order. MUST run inside the caller's transaction (it throws otherwise):
     * the counters, the reservation rows and the order commit or roll back together.
     * Products are processed in ascending product_id order whatever the strategy.
     *
     * @param  array<string, int>  $quantities  product_id => quantity (>= 1)
     *
     * @throws \Modules\Shared\Contracts\ProblemException 409 "out-of-stock" with product_ids = [first insufficient product]
     */
    public function reserve(string $orderId, array $quantities, DateTimeInterface $expiresAt): void;

    /** Active reservations -> released; stock goes back to available. Idempotent. Returns rows released. */
    public function release(string $orderId): int;

    /** Active reservations -> committed; reserved becomes sold. Idempotent. Returns rows committed. */
    public function commit(string $orderId): int;

    /**
     * Active reservations past expires_at -> expired, stock back to available, at most $limit rows,
     * SKIP LOCKED so several workers can run it. Idempotent.
     *
     * @return list<string> distinct order ids whose reservations expired (Task 6 moves those orders to "expired")
     */
    public function releaseExpired(int $limit): array;
}
