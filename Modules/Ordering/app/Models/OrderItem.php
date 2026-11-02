<?php

declare(strict_types=1);

namespace Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Price snapshot taken from the ProductPricing contract at order time (server-side, minor units).
 *
 * @property string $id
 * @property string $order_id
 * @property string $product_id
 * @property int $quantity
 * @property int $unit_price_minor
 * @property string $currency
 * @property int $line_total_minor
 */
final class OrderItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['id', 'order_id', 'product_id', 'quantity', 'unit_price_minor', 'currency', 'line_total_minor'];

    protected $casts = ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
