<?php

declare(strict_types=1);

namespace Modules\Ordering\Events;

/** Stock is reserved for the order (status reserved). Task 6 schedules the expiry from here. */
final readonly class OrderReserved extends OrderEvent {}
