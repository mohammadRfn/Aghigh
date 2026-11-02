<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use Modules\Shared\Contracts\ProblemException;
use RuntimeException;

/** 409 "out-of-stock". Every strategy reports the FIRST insufficient product in ascending product_id order. */
final class OutOfStock extends RuntimeException implements ProblemException
{
    /** @param list<string> $productIds */
    public function __construct(private readonly array $productIds)
    {
        parent::__construct('Insufficient stock for the requested quantity.');
    }

    public static function for(string $productId): self
    {
        return new self([$productId]);
    }

    public function problemStatus(): int
    {
        return 409;
    }

    public function problemType(): string
    {
        return 'out-of-stock';
    }

    public function problemExtensions(): array
    {
        return ['product_ids' => $this->productIds];
    }
}
