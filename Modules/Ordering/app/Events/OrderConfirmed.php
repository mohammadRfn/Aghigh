<?php

declare(strict_types=1);

namespace Modules\Ordering\Events;

/** Saga pivot passed: the order is paid AND its reservations are committed (reserved -> sold). */
final readonly class OrderConfirmed extends OrderEvent {}
