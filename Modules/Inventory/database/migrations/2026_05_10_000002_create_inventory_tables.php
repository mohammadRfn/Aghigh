<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->uuid('product_id')->primary(); // Catalog product id, no cross-module FK (ADR 0001)
            $table->bigInteger('initial')->default(0);
            $table->bigInteger('available')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->bigInteger('sold')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id'); // Ordering order id, referenced by id only
            $table->uuid('product_id');
            $table->integer('quantity');
            $table->string('status', 10); // active | released | committed | expired
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
            $table->index(['product_id', 'status']); // verify-invariants aggregates
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_items ADD CONSTRAINT stock_items_non_negative CHECK (initial >= 0 AND available >= 0 AND reserved >= 0 AND sold >= 0)');
            DB::statement('ALTER TABLE stock_items ADD CONSTRAINT stock_items_balanced CHECK (available + reserved + sold = initial)');
            DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('active', 'released', 'committed', 'expired'))");
            // releaseExpired() scans only active rows ordered by expiry.
            DB::statement("CREATE INDEX stock_reservations_active_expiry_idx ON stock_reservations (expires_at, id) WHERE status = 'active'");
        } else {
            Schema::table('stock_reservations', fn (Blueprint $table) => $table->index(['status', 'expires_at']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_items');
    }
};
