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
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id'); // Identity user id, referenced by id only (ADR 0001)
            $table->string('status', 12);
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('version')->default(1); // bumped by every state transition
            $table->timestamp('expires_at')->nullable();  // reservation deadline (Task 6 expiry job)
            $table->timestamps();
            $table->index(['user_id', 'id']);          // GET /orders keyset (uuid7 = time-ordered)
            $table->index(['status', 'expires_at']);  // expiry job
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->uuid('product_id'); // Catalog product id, no FK
            $table->integer('quantity');
            $table->bigInteger('unit_price_minor'); // snapshot from ProductPricing
            $table->char('currency', 3);
            $table->bigInteger('line_total_minor');
            $table->unique(['order_id', 'product_id']); // duplicates are rejected, never merged
            $table->index('product_id');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('key', 64);
            $table->char('request_hash', 64);
            $table->string('status', 12); // in_progress | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->uuid('order_id')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();
            $table->unique(['user_id', 'key']); // the INSERT ... ON CONFLICT DO NOTHING target
        });
        // Exactly one key per order (POST /orders is the only creation path). Partial unique index works on pgsql and sqlite.
        DB::statement('CREATE UNIQUE INDEX idempotency_keys_order_id_unique ON idempotency_keys (order_id) WHERE order_id IS NOT NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'reserved', 'paid', 'fulfilled', 'cancelled', 'expired'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_non_negative CHECK (total_minor >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check CHECK (status IN ('in_progress', 'completed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
