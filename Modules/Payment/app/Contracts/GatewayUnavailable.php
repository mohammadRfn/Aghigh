<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

use Throwable;

/** Transient gateway failure (timeout, 5xx): outcome unknown, retrying with the same idempotency key is safe. */
interface GatewayUnavailable extends Throwable {}
