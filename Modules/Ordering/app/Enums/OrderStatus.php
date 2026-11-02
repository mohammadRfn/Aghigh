<?php

declare(strict_types=1);

namespace Modules\Ordering\Enums;

/**
 * pending -> reserved -> paid -> fulfilled
 * cancelled: from pending, reserved and (refund path, Task 6) paid. expired: from reserved.
 * "pending" never exists outside the creating transaction: reservation happens in the same request.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Paid = 'paid';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Pending->value => [self::Reserved, self::Cancelled],
            self::Reserved->value => [self::Paid, self::Cancelled, self::Expired],
            self::Paid->value => [self::Fulfilled, self::Cancelled], // cancel from paid = refund path, no HTTP route in Task 5
            self::Fulfilled->value => [],
            self::Cancelled->value => [],
            self::Expired->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value], true);
    }

    public function isTerminal(): bool
    {
        return self::transitions()[$this->value] === [];
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_values(array_map(fn (self $status): string => $status->value, array_filter(self::cases(), fn (self $status): bool => $status->isTerminal())));
    }

    /** Outbox row type written when an order ENTERS this status. */
    public function eventType(): string
    {
        return 'Order'.ucfirst($this->value);
    }
}
