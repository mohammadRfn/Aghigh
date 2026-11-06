<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\AdminCategoryController;
use Modules\Catalog\Http\Controllers\AdminProductController;
use Modules\Catalog\Http\Controllers\CategoryController;
use Modules\Catalog\Http\Controllers\NaiveProductController;
use Modules\Catalog\Http\Controllers\ProductController;
use Modules\Catalog\Http\Middleware\AdminHeaders;
use Modules\Catalog\Http\Middleware\BenchOnly;
use Modules\Catalog\Support\BenchEnvironment;

Route::get('catalog/ping', fn () => response()->json(['module' => 'Catalog', 'status' => 'ok']))->name('catalog.ping');

// Task 7: public reads are limited per IP and FAIL OPEN (cached, harmless); admin writes per user, fail closed.
Route::middleware('throttle.sliding:public-catalog')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('catalog.products.index');
    Route::get('products/{identifier}', [ProductController::class, 'show'])->name('catalog.products.show');
    Route::get('categories', [CategoryController::class, 'index'])->name('catalog.categories.index');
});

Route::prefix('admin')->middleware([AdminHeaders::class, 'auth:api', 'throttle.sliding:admin'])->name('catalog.admin.')->group(function (): void {
    Route::post('products', [AdminProductController::class, 'store'])->name('products.store');
    Route::patch('products/{id}', [AdminProductController::class, 'update'])->whereUuid('id')->name('products.update');
    Route::get('products/{id}', [AdminProductController::class, 'show'])->whereUuid('id')->name('products.show');
    Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{id}', [AdminCategoryController::class, 'update'])->whereUuid('id')->name('categories.update');
});

if (BenchEnvironment::enabled(app()->environment())) {
    Route::get('_bench/naive/products', [NaiveProductController::class, 'index'])
        ->middleware(BenchOnly::class)->name('catalog.bench.naive');
}
