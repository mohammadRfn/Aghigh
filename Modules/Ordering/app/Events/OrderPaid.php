<?php

declare(strict_types=1);

namespace Modules\Ordering\Events;

/** Payment confirmed; reservations are committed (Task 6). */
final readonly class OrderPaid extends OrderEvent {}
