<?php

declare(strict_types=1);

namespace Modules\Ordering\DTOs;

/** Outcome of PlaceOrder: the HTTP status and body to send, and whether it was replayed from a stored response. */
final readonly class OrderPlacement
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
        public ?string $orderId,
    ) {}
}
