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
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('email', 254);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 20)->default('customer');
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampsTz();
        });
        // A new, empty table: normal index creation is safe. SQLite also supports this expression.
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        Schema::create('identity_token_families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
        });

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('family_id')->constrained('identity_token_families')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable()->index();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->index('user_id');
            $table->index('family_id');
        });

        // A durable ledger makes family/user revocation enumerate all still-live JWTs.
        Schema::create('identity_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary(); // JWT jti
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('family_id')->constrained('identity_token_families')->cascadeOnDelete();
            $table->timestampTz('expires_at')->index();
            $table->index('user_id');
            $table->index('family_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
            $table->index('user_id');
        });

        Schema::create('identity_mail_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->uuid('reset_id')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['identity_mail_outbox', 'password_reset_tokens', 'identity_access_tokens', 'refresh_tokens', 'identity_token_families', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
