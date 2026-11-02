<?php

declare(strict_types=1);

namespace Modules\Ordering\Events;

/** The order was cancelled and its reservations released. */
final readonly class OrderCancelled extends OrderEvent {}
