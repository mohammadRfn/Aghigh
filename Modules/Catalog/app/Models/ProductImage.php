<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $product_id
 * @property string $path
 * @property int $width
 * @property int $height
 * @property int $position
 * @property string $alt
 */
final class ProductImage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['path', 'width', 'height', 'position', 'alt'];

    protected $casts = ['width' => 'integer', 'height' => 'integer', 'position' => 'integer'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
