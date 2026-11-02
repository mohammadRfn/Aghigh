<?php

declare(strict_types=1);

namespace Modules\Ordering\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Read model only: PlaceOrder writes these rows with raw statements inside the order transaction.
 * Only status "completed" is ever visible outside that transaction.
 *
 * @property string $id
 * @property string $user_id
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property string|null $order_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $completed_at
 */
final class IdempotencyKey extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'response_status' => 'integer',
        'response_body' => 'array',
        'created_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
