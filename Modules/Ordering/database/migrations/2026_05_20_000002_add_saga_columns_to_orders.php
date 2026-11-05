<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable columns without defaults: a catalog-only change on PostgreSQL (no table rewrite).
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable(); // saga pivot: stock committed + OrderConfirmed recorded
            $table->timestamp('notified_at')->nullable();  // confirmation handed to the notifications queue
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'notified_at']);
        });
    }
};
