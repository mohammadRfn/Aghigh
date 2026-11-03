<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Ordering\Models\Order;

it('asks the real planner to serve GET /orders from the (user_id, id) index without sorting', function (): void {
    // 60,000 orders over 300 users; the user under test owns ~200 of them.
    DB::statement(<<<'SQL'
        INSERT INTO orders (id, user_id, status, total_minor, currency, version, created_at, updated_at)
        SELECT uuidv7(),
               ('00000000-0000-7000-8000-' || lpad((g.i % 300)::text, 12, '0'))::uuid,
               CASE WHEN g.i % 4 = 0 THEN 'cancelled' ELSE 'reserved' END,
               1000, 'IRR', 2, now(), now()
        FROM generate_series(1, 60000) AS g(i)
    SQL);
    DB::statement('ANALYZE orders');
    $user = '00000000-0000-7000-8000-000000000007';
    $cursor = (string) DB::table('orders')->where('user_id', $user)->orderByDesc('id')->offset(50)->value('id');

    $shapes = [
        'first page' => Order::query()->where('user_id', $user)->orderByDesc('id')->limit(21),
        'cursor page' => Order::query()->where('user_id', $user)->where('id', '<', $cursor)->orderByDesc('id')->limit(21),
        'status filter' => Order::query()->where('user_id', $user)->where('status', 'cancelled')->orderByDesc('id')->limit(21),
    ];

    foreach ($shapes as $label => $query) {
        // The planner is not forced (no enable_seqscan=off): a different plan is evidence, not something to hide.
        $plan = (string) ((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings()))['QUERY PLAN'];
        $this->assertStringContainsString('orders_user_id_id_index', $plan, $label.' plan: '.$plan);
        $this->assertStringNotContainsString('"Node Type": "Sort"', $plan, $label.' sorts: '.$plan);
        $this->assertStringNotContainsString('"Seq Scan"', $plan, $label.' scans sequentially: '.$plan);
    }
});
