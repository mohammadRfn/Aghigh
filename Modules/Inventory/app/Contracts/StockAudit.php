<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

/** Read-only checks used by orders:verify-invariants. Never part of a request path. */
interface StockAudit
{
    /** @return list<string> "<rule>:<product_id>" for every ledger violation; empty when consistent */
    public function violations(): array;

    /**
     * @param  list<string>  $orderIds
     * @return array<string, array<string, int>> order_id => [reservation status => row count]
     */
    public function reservationStates(array $orderIds): array;
}
