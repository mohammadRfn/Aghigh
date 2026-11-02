<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per product (product_id is the key, referenced by id only: no FK into Catalog).
 * Invariant enforced by Postgres CHECKs and verified by orders:verify-invariants:
 * all counters >= 0 and available + reserved + sold = initial.
 *
 * @property string $product_id
 * @property int $initial
 * @property int $available
 * @property int $reserved
 * @property int $sold
 */
final class StockItem extends Model
{
    protected $table = 'stock_items';

    protected $primaryKey = 'product_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['product_id', 'initial', 'available', 'reserved', 'sold'];

    protected $casts = ['initial' => 'integer', 'available' => 'integer', 'reserved' => 'integer', 'sold' => 'integer'];
}
