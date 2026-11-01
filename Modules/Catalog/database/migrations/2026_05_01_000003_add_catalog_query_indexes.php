<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'catalog_active_category_id' => "ON products (category_id, id) WHERE status = 'active'",
        'catalog_active_price_id' => "ON products (price_minor, id) WHERE status = 'active'",
        'catalog_active_category_price_id' => "ON products (category_id, price_minor, id) WHERE status = 'active'",
        'catalog_active_name_trgm' => "ON products USING gin (lower(name) gin_trgm_ops) WHERE status = 'active'",
        'catalog_images_product_position' => 'ON product_images (product_id, position)',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::INDEXES as $name => $definition) {
            // Retry a failed concurrent build instead of preserving an INVALID index.
            $existing = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
            if ($existing !== null && ! $existing->indisvalid) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} {$definition}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
