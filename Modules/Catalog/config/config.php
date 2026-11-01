<?php

declare(strict_types=1);

return [
    'name' => 'Catalog',
    // Defaults are configuration, never benchmark measurements.
    'currency' => env('CATALOG_CURRENCY', 'IRR'),
    'image_base_url' => env('CATALOG_IMAGE_BASE_URL', 'https://cdn.example.com'),
    'per_page' => (int) env('CATALOG_PER_PAGE', 20),
    'per_page_max' => (int) env('CATALOG_PER_PAGE_MAX', 50),
    'cache_fresh_seconds' => (int) env('CATALOG_CACHE_FRESH_SECONDS', 60),
    // Total lifetime, including the fresh window (Laravel flexible semantics).
    'cache_stale_seconds' => (int) env('CATALOG_CACHE_STALE_SECONDS', 180),
    'cache_lock_seconds' => (int) env('CATALOG_CACHE_LOCK_SECONDS', 15),
    'cache_lock_wait_seconds' => (int) env('CATALOG_CACHE_LOCK_WAIT_SECONDS', 5),
    'pricing_max_ids' => (int) env('CATALOG_PRICING_MAX_IDS', 100),
    'images_max' => (int) env('CATALOG_IMAGES_MAX', 10),
    'category_max_depth' => (int) env('CATALOG_CATEGORY_MAX_DEPTH', 5),
    // JSON-safe, positive integer money, also safely inside signed bigint.
    'price_max_minor' => (int) env('CATALOG_PRICE_MAX_MINOR', 9007199254740991),
];
