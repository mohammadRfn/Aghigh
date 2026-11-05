<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockAudit;

/** The stock ledger rules that must hold after any mix of reserve, release, commit and expire. */
final class StockAuditService implements StockAudit
{
    public function violations(): array
    {
        $found = [];
        foreach (DB::select('SELECT product_id FROM stock_items WHERE available + reserved + sold <> initial') as $row) {
            $found[] = 'stock-unbalanced:'.$row->product_id;
        }
        foreach (DB::select('SELECT product_id FROM stock_items WHERE available < 0 OR reserved < 0 OR sold < 0') as $row) {
            $found[] = 'stock-negative:'.$row->product_id;
        }
        foreach (DB::select(<<<'SQL'
            SELECT s.product_id FROM stock_items s
            WHERE s.reserved <> COALESCE((SELECT SUM(r.quantity) FROM stock_reservations r WHERE r.product_id = s.product_id AND r.status = 'active'), 0)
        SQL) as $row) {
            $found[] = 'stock-reserved-drift:'.$row->product_id;
        }
        foreach (DB::select(<<<'SQL'
            SELECT s.product_id FROM stock_items s
            WHERE s.sold <> COALESCE((SELECT SUM(r.quantity) FROM stock_reservations r WHERE r.product_id = s.product_id AND r.status = 'committed'), 0)
        SQL) as $row) {
            $found[] = 'stock-sold-drift:'.$row->product_id;
        }

        return $found;
    }

    public function reservationStates(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $states = [];
        $rows = DB::table('stock_reservations')->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, status, COUNT(*) AS rows_count')->groupBy('order_id', 'status')->get();
        foreach ($rows as $row) {
            $states[(string) $row->order_id][(string) $row->status] = (int) $row->rows_count;
        }

        return $states;
    }
}
