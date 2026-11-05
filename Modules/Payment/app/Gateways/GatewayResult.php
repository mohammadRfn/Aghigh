<?php

declare(strict_types=1);

namespace Modules\Payment\Gateways;

final readonly class GatewayResult
{
    public function __construct(public bool $approved, public ?string $reference, public ?string $code) {}

    public static function approved(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function declined(string $code): self
    {
        return new self(false, null, $code);
    }

    /** @return array{approved: bool, reference: string|null, code: string|null} */
    public function toArray(): array
    {
        return ['approved' => $this->approved, 'reference' => $this->reference, 'code' => $this->code];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['approved'] ?? false),
            isset($data['reference']) ? (string) $data['reference'] : null,
            isset($data['code']) ? (string) $data['code'] : null,
        );
    }
}
