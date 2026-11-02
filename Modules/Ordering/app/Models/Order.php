<?php

declare(strict_types=1);

namespace Modules\Ordering\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Ordering\Enums\OrderStatus;

/**
 * Status changes go through OrderStateMachine only (conditional UPDATE + outbox); never save() a status.
 *
 * @property string $id
 * @property string $user_id
 * @property OrderStatus $status
 * @property int $total_minor
 * @property string $currency
 * @property int $version
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, OrderItem> $items
 */
final class Order extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'user_id', 'status', 'total_minor', 'currency', 'version', 'expires_at'];

    protected $casts = [
        'status' => OrderStatus::class,
        'total_minor' => 'integer',
        'version' => 'integer',
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('product_id');
    }
}
