<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 160)->unique();
            $table->unsignedInteger('position')->default(0);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('sku', 80)->unique();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->text('description')->default('');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->enum('status', ['active', 'draft', 'archived'])->default('draft');
            $table->timestamps();
        });
        Schema::create('product_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path', 500);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('position')->default(0);
            $table->string('alt', 250)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
