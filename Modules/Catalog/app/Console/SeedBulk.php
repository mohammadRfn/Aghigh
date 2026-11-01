<?php

declare(strict_types=1);

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Services\CatalogState;
use Modules\Catalog\Services\CatalogVersions;
use Throwable;

final class SeedBulk extends Command
{
    protected $signature = 'catalog:seed-bulk {--categories=200} {--products=100000} {--images-per-product=3} {--truncate}';

    protected $description = 'Generate deterministic catalog distributions using PostgreSQL 18 set-based SQL (migrator only).';

    // Dataset design constants, NOT measured percentages or performance results.
    private const BATCH = 50000;

    private const ACTIVE_PERCENT = 85;

    private const DRAFT_END_PERCENT = 95;

    private const ROOT_CATEGORIES = 20;

    private const HOT_SHARE_TENTHS = 8;

    private const WORDS = ['گلدان', 'ceramic', 'linen', 'handmade', 'کوسن', 'cotton', 'stoneware', 'فنجان', 'woven', 'natural', 'ظرف', 'classic', 'blue', 'walnut', 'بشقاب', 'vintage', 'minimal', 'brass', 'چراغ', 'soft', 'wooden', 'pattern', 'زعفران', 'clay', 'silver', 'artisan', 'فرش', 'glass', 'green', 'basket', 'ترمه', 'light'];

    public function handle(CatalogVersions $versions): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed a production catalog.');

            return self::FAILURE;
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('This command requires PostgreSQL 18 directly as the migrator role. Use make seed-catalog.');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        if (str_contains(strtolower((string) config("database.connections.{$connection}.host")), 'pgbouncer')) {
            $this->error('Do not run through PgBouncer. Use make seed-catalog (DB_HOST=postgres, migrator credentials).');

            return self::FAILURE;
        }
        foreach (['categories', 'products', 'images-per-product'] as $option) {
            if (! ctype_digit((string) $this->option($option))) {
                $this->error("--{$option} must be a non-negative integer.");

                return self::FAILURE;
            }
        }
        $categories = (int) $this->option('categories');
        $products = (int) $this->option('products');
        $images = (int) $this->option('images-per-product');
        if ($categories < 2 || $categories > 100000 || $products < 1 || $products > 10000000 || $images > (int) config('catalog.images_max')) {
            $this->error('Bounds: categories 2..100000, products 1..10000000, images 0..catalog.images_max.');

            return self::FAILURE;
        }
        $version = (array) DB::selectOne('SHOW server_version_num');
        if ((int) ($version['server_version_num'] ?? 0) < 180000) {
            $this->error('PostgreSQL 18 is required for uuidv7().');

            return self::FAILURE;
        }
        foreach (['categories', 'products', 'product_images'] as $table) {
            $privilege = (array) DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS allowed', [$table, 'TRUNCATE']);
            if (! ($privilege['allowed'] ?? false)) {
                $this->error("Current role lacks TRUNCATE on {$table}. Run make seed-catalog as the migrator, not the app role.");

                return self::FAILURE;
            }
            if (! $this->option('truncate') && DB::table($table)->exists()) {
                $this->error('Catalog tables are not empty. Use --truncate explicitly to replace this development dataset.');

                return self::FAILURE;
            }
        }
        try {
            $versions->initialize();
            if (CatalogState::store()->increment(CatalogVersions::DIRTY) === false) {
                throw new \RuntimeException('State write failed.');
            }
        } catch (Throwable) {
            $this->error('State Redis is unavailable; refusing to change data without marking cache invalidation.');

            return self::FAILURE;
        }
        try {
            if ($this->option('truncate')) {
                DB::statement('TRUNCATE TABLE product_images, products, categories');
            }
            $roots = min(self::ROOT_CATEGORIES, max(1, intdiv($categories, 10)));
            $leaves = $categories - $roots;
            $hot = max(1, intdiv($leaves, 5));
            DB::insert("INSERT INTO categories (id, parent_id, name, slug, position) SELECT uuidv7(), NULL, 'Collection ' || i, 'bulk-category-' || i, i FROM generate_series(1, ?) AS g(i)", [$roots]);
            for ($from = $roots + 1; $from <= $categories; $from += self::BATCH) {
                $to = min($categories, $from + self::BATCH - 1);
                DB::transaction(fn (): bool => DB::insert("INSERT INTO categories (id, parent_id, name, slug, position) SELECT uuidv7(), c.id, 'Category ' || g.i, 'bulk-category-' || g.i, g.i FROM generate_series(?::int, ?::int) AS g(i) JOIN categories c ON c.slug = 'bulk-category-' || (1 + ((g.i - ? - 1) % ?))", [$from, $to, $roots, $roots]));
            }
            $words = 'ARRAY['.implode(',', array_map(static fn (string $word): string => "'".str_replace("'", "''", $word)."'", self::WORDS)).']';
            $this->info("Seeding {$products} products. Constants: 85% active, 10% draft, 5% archived; skewed categories and integer prices.");
            for ($from = 1; $from <= $products; $from += self::BATCH) {
                $to = min($products, $from + self::BATCH - 1);
                $sql = "INSERT INTO products (id, category_id, sku, name, slug, description, price_minor, currency, status, created_at, updated_at)
                    SELECT uuidv7(), c.id, 'BULK-' || lpad(g.i::text, 10, '0'),
                    ({$words})[1 + (g.i % 32)] || ' collection ' || g.i,
                    'bulk-product-' || g.i, 'Deterministic catalog fixture; not a measured workload.',
                    LEAST(CASE WHEN g.i % 100 < 70 THEN 50000 + (g.i * 7919::bigint) % 950000
                      WHEN g.i % 100 < 95 THEN 1000000 + (g.i * 3571::bigint) % 9000000
                      ELSE 10000000 + (g.i * 997::bigint) % 90000000 END, ?::bigint), ?,
                    CASE WHEN g.i % 100 < ".self::ACTIVE_PERCENT." THEN 'active' WHEN g.i % 100 < ".self::DRAFT_END_PERCENT." THEN 'draft' ELSE 'archived' END,
                    timestamp '2024-01-01' + g.i * interval '1 second', timestamp '2024-01-01' + g.i * interval '1 second'
                    FROM generate_series(?::int, ?::int) AS g(i)
                    JOIN categories c ON c.slug = 'bulk-category-' || (? + 1 + CASE WHEN g.i % 10 < ".self::HOT_SHARE_TENTHS.' THEN g.i % ? ELSE g.i % ? END)';
                DB::transaction(fn (): bool => DB::insert($sql, [(int) config('catalog.price_max_minor'), (string) config('catalog.currency'), $from, $to, $roots, $hot, $leaves]));
                $this->line("Products: {$to}/{$products}");
            }
            if ($images > 0) {
                $productBatch = max(1, intdiv(self::BATCH, $images));
                for ($from = 1; $from <= $products; $from += $productBatch) {
                    $to = min($products, $from + $productBatch - 1);
                    DB::transaction(fn (): bool => DB::insert("INSERT INTO product_images (id, product_id, path, width, height, position, alt)
                        SELECT uuidv7(), p.id, 'catalog/' || g.i || '/' || image.i || '.jpg', 1200, 1200, image.i, 'Product ' || g.i || ', image ' || image.i
                        FROM generate_series(?::int, ?::int) AS g(i)
                        JOIN products p ON p.sku = 'BULK-' || lpad(g.i::text, 10, '0')
                        CROSS JOIN generate_series(0, ?) AS image(i)", [$from, $to, $images - 1]));
                    $this->line('Images: '.($to * $images).'/'.($products * $images));
                }
            }
            // The specification defines THREE tables, not four. See the README.
            DB::statement('ANALYZE categories, products, product_images');
            $versions->bump();
            if (CatalogState::store()->decrement(CatalogVersions::DIRTY) === false) {
                throw new \RuntimeException('Invalidation could not finish.');
            }
            $this->info('Done. All three catalog tables analyzed. No performance result is claimed.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Bulk seed stopped: '.$error->getMessage());
            $this->warn('Completed batches remain committed. Fix the cause, retry with --truncate, then reconcile cache with writes paused.');

            return self::FAILURE;
        }
    }
}
