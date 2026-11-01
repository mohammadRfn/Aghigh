<?php

declare(strict_types=1);

namespace Modules\Catalog\DTOs;

final readonly class ProductPrice
{
    public function __construct(
        public int $price_minor,
        public string $currency,
        public bool $active,
    ) {}
}
