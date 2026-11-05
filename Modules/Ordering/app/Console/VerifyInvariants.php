<?php

declare(strict_types=1);

namespace Modules\Ordering\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Inventory\Contracts\StockAudit;
use Modules\Ordering\Enums\OrderStatus;
use Modules\Ordering\Models\Order;
use Modules\Payment\Contracts\PaymentLedger;
use Modules\Payment\Contracts\PaymentStatus;

/**
 * Cross-module consistency check, meant for a QUIESCENT system (run after queue:drain-time).
 * Stock ledger rules come from Inventory (StockAudit), payments from Payment (PaymentLedger);
 * Ordering only combines them per order. Exit code 1 when anything is wrong.
 */
final class VerifyInvariants extends Command
{
    protected $signature = 'orders:verify-invariants {--json : One JSON document on stdout} {--chunk=1000 : Orders per round trip} {--max-report=50 : Violations printed (all are counted)}';

    protected $description = 'Verify orders, stock reservations, stock counters and payments agree';

    public function handle(StockAudit $stock, PaymentLedger $payments): int
    {
        $violations = $stock->violations();
        $checked = 0;
        Order::query()->select(['id', 'status', 'confirmed_at'])->orderBy('id')
            ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $orders) use ($stock, $payments, &$violations, &$checked): void {
                /** @var list<string> $ids */
                $ids = $orders->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
                $reservations = $stock->reservationStates($ids);
                $states = $payments->statuses($ids);
                foreach ($orders as $order) {
                    foreach (self::check($order->status, $order->confirmed_at !== null, $reservations[$order->id] ?? [], $states[$order->id] ?? null) as $rule) {
                        $violations[] = $rule.':'.$order->id;
                    }
                    $checked++;
                }
            });

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $violations === [], 'orders_checked' => $checked, 'violation_count' => count($violations), 'violations' => array_slice($violations, 0, 1000)], JSON_THROW_ON_ERROR));
        } elseif ($violations === []) {
            $this->info("OK: {$checked} orders, stock ledger and payments are consistent.");
        } else {
            foreach (array_slice($violations, 0, max(1, (int) $this->option('max-report'))) as $violation) {
                $this->error($violation);
            }
            $this->error(count($violations).' violation(s).');
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Rules for one order.
     *
     * @param  array<string, int>  $reservations  reservation status => rows
     * @return list<string>
     */
    public static function check(OrderStatus $status, bool $confirmed, array $reservations, ?PaymentStatus $payment): array
    {
        $total = array_sum($reservations);
        $active = $reservations['active'] ?? 0;
        $committed = $reservations['committed'] ?? 0;
        $sold = $confirmed || $status === OrderStatus::Fulfilled;
        $closed = in_array($status, [OrderStatus::Cancelled, OrderStatus::Expired], true);
        $found = [];

        if ($status === OrderStatus::Pending) {
            $found[] = 'order-stuck-pending';
        }
        if ($total === 0 && $status !== OrderStatus::Pending) {
            $found[] = 'order-without-reservations';
        }
        if ($confirmed && ! in_array($status, [OrderStatus::Paid, OrderStatus::Fulfilled], true)) {
            $found[] = 'confirmed-order-not-paid';
        }
        if ($sold && $committed !== $total) {
            $found[] = 'confirmed-order-stock-not-committed';
        }
        if (! $sold && $committed > 0) {
            $found[] = 'unconfirmed-order-has-sold-stock';
        }
        if (! $sold && ! $closed && $active !== $total) {
            $found[] = 'open-order-stock-not-reserved';
        }
        if ($closed && $active > 0) {
            $found[] = 'closed-order-still-holds-stock';
        }
        if (in_array($status, [OrderStatus::Paid, OrderStatus::Fulfilled], true) && $payment !== PaymentStatus::Succeeded) {
            $found[] = 'paid-order-without-succeeded-payment';
        }
        if ($closed && in_array($payment, [PaymentStatus::Succeeded, PaymentStatus::Pending], true)) {
            $found[] = 'closed-order-payment-not-refunded';
        }
        if ($status === OrderStatus::Reserved && in_array($payment, [PaymentStatus::Succeeded, PaymentStatus::Failed, PaymentStatus::Refunded], true)) {
            $found[] = 'reserved-order-with-settled-payment';
        }

        return $found;
    }
}
