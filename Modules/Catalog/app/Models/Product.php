<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Catalog\Database\Factories\ProductFactory;
use Modules\Catalog\Exceptions\CatalogProblem;
use Modules\Catalog\Relations\ImageOfMany;

/**
 * @property string $id
 * @property string $category_id
 * @property string $sku
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property int $price_minor
 * @property string $currency
 * @property string $status
 * @property Category $category
 * @property ProductImage|null $primaryImage
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['category_id', 'sku', 'name', 'slug', 'description', 'price_minor', 'currency', 'status'];

    protected $casts = ['price_minor' => 'integer'];

    protected static function booted(): void
    {
        self::saving(function (self $product): void {
            if ($product->currency !== config('catalog.currency') || ! preg_match('/^[A-Z]{3}$/D', $product->currency)) {
                throw new CatalogProblem('Currency must equal the configured catalog currency.');
            }
            if ($product->price_minor < 1 || $product->price_minor > (int) config('catalog.price_max_minor')) {
                throw new CatalogProblem('Price must be a positive, bounded integer in minor units.');
            }
        });
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function primaryImage(): ImageOfMany
    {
        return (new ImageOfMany((new ProductImage)->newQuery(), $this, 'product_images.product_id', 'id'))
            ->ofMany('position', 'min', 'primaryImage');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
