<?php

declare(strict_types=1);

namespace Modules\Ordering\Events;

/** The reservation deadline passed; stock was released (Task 6 expiry job). */
final readonly class OrderExpired extends OrderEvent {}
