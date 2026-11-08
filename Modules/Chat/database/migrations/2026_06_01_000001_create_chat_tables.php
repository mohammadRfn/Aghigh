<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Task 8: chat tables. Run as the migrator (`make migrate`). Ids of other modules (customer_id,
 | assigned_agent_id, sender_id, user_id, agent_id) are plain indexed uuid columns without foreign keys
 | (ADR 0001); only intra-module foreign keys to `conversations` exist. New tables, so plain CREATE INDEX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('status', 16);
            $table->uuid('assigned_agent_id')->nullable();
            $table->bigInteger('last_seq')->default(0);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index(['customer_id', 'id'], 'conversations_customer_list_idx');
            $table->index(['assigned_agent_id', 'id'], 'conversations_agent_list_idx');
        });

        // One open conversation per customer, enforced by the database (POST /conversations is race-safe on it).
        DB::statement("CREATE UNIQUE INDEX conversations_one_open_per_customer ON conversations (customer_id) WHERE status <> 'closed'");
        // Queue sweep / rebuild: waiting conversations in enqueue order.
        DB::statement("CREATE INDEX conversations_waiting_sweep_idx ON conversations (status, created_at) WHERE status = 'waiting'");

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->bigInteger('seq');
            $table->uuid('sender_id')->index();
            $table->string('sender_role', 16);
            $table->text('body');
            $table->string('client_message_id', 64);
            $table->timestampTz('created_at', 6);
            $table->unique(['conversation_id', 'seq'], 'messages_conversation_seq_unique');
            $table->unique(['conversation_id', 'client_message_id'], 'messages_conversation_client_id_unique');
        });

        Schema::create('conversation_reads', function (Blueprint $table): void {
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->uuid('user_id')->index();
            $table->bigInteger('last_read_seq')->default(0);
            $table->timestampTz('updated_at', 6);
            $table->primary(['conversation_id', 'user_id']);
        });

        Schema::create('assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->uuid('agent_id')->index();
            $table->timestampTz('assigned_at', 6);
            $table->timestampTz('released_at', 6)->nullable();
            $table->string('reason', 16);
            $table->index(['conversation_id', 'assigned_at'], 'assignments_history_idx');
        });

        // At most one open assignment per conversation (the invariant checker additionally requires exactly one when claimed).
        DB::statement('CREATE UNIQUE INDEX assignments_one_open ON assignments (conversation_id) WHERE released_at IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('waiting', 'claimed', 'closed'))");
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_claimed_has_agent CHECK (status <> 'claimed' OR assigned_agent_id IS NOT NULL)");
            DB::statement('ALTER TABLE conversations ADD CONSTRAINT conversations_last_seq_check CHECK (last_seq >= 0)');
            DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_seq_check CHECK (seq >= 1)');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_sender_role_check CHECK (sender_role IN ('customer', 'support_agent'))");
            DB::statement("ALTER TABLE assignments ADD CONSTRAINT assignments_reason_check CHECK (reason IN ('claimed', 'released', 'closed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('conversation_reads');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
