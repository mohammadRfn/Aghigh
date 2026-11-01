<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Catalog\Database\Factories\CategoryFactory;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property string $slug
 * @property int $position
 */
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['parent_id', 'name', 'slug', 'position'];

    protected $casts = ['position' => 'integer'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
