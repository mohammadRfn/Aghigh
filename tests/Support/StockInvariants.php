<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** The stock ledger rules that must hold after any mix of reserve, release, commit and expire. */
final class StockInvariants
{
    /** @return list<string> "<rule>:<product_id>" for every violation; empty when the ledger is consistent */
    public static function violations(): array
    {
        $found = [];

        foreach (DB::select('SELECT product_id FROM stock_items WHERE available + reserved + sold <> initial') as $row) {
            $found[] = 'unbalanced:'.$row->product_id;
        }
        foreach (DB::select('SELECT product_id FROM stock_items WHERE available < 0 OR reserved < 0 OR sold < 0') as $row) {
            $found[] = 'negative:'.$row->product_id;
        }
        foreach (DB::select(<<<'SQL'
            SELECT s.product_id FROM stock_items s
            WHERE s.reserved <> COALESCE((SELECT SUM(r.quantity) FROM stock_reservations r WHERE r.product_id = s.product_id AND r.status = 'active'), 0)
        SQL) as $row) {
            $found[] = 'drift:'.$row->product_id;
        }
        foreach (DB::select(<<<'SQL'
            SELECT s.product_id FROM stock_items s
            WHERE s.sold <> COALESCE((SELECT SUM(r.quantity) FROM stock_reservations r WHERE r.product_id = s.product_id AND r.status = 'committed'), 0)
        SQL) as $row) {
            $found[] = 'sold-drift:'.$row->product_id;
        }

        return $found;
    }
}
