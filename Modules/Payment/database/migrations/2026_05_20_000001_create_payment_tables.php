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
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique(); // ONE payment per order; Ordering id by reference only (ADR 0001)
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 10); // pending | succeeded | failed | refunded
            $table->unsignedSmallInteger('attempts')->default(0); // gateway calls (charge + refund)
            $table->string('gateway_reference', 64)->nullable();
            $table->string('refund_reference', 64)->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->string('last_error', 64)->nullable(); // error class/code only, never a message
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_id', 100)->unique(); // gateway event id: webhook idempotency
            $table->string('type', 64);
            $table->uuid('order_id')->nullable()->index();
            $table->jsonb('payload');
            $table->timestamp('signed_at');
            $table->timestamp('received_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_non_negative CHECK (amount_minor >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
