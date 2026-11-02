<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Inventory\Enums\ReservationStatus;

/**
 * @property string $id
 * @property string $order_id
 * @property string $product_id
 * @property int $quantity
 * @property ReservationStatus $status
 * @property CarbonImmutable $expires_at
 */
final class StockReservation extends Model
{
    use HasUuids;

    protected $table = 'stock_reservations';

    protected $fillable = ['order_id', 'product_id', 'quantity', 'status', 'expires_at'];

    protected $casts = ['quantity' => 'integer', 'status' => ReservationStatus::class, 'expires_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
