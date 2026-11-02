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
        Schema::create('outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary(); // uuid7: time-ordered
            $table->string('aggregate_type', 32);
            $table->string('aggregate_id', 36); // referenced by id only (ADR 0001)
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestamp('occurred_at', 6);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at', 6); // relay backoff
            $table->timestamp('processed_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // The relay only ever scans unprocessed rows.
            DB::statement('CREATE INDEX outbox_pending_idx ON outbox (available_at, id) WHERE processed_at IS NULL');
        } else {
            Schema::table('outbox', fn (Blueprint $table) => $table->index(['processed_at', 'available_at']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
