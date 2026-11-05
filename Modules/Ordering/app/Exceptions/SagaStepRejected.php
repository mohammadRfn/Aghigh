<?php

declare(strict_types=1);

namespace Modules\Ordering\Exceptions;

use RuntimeException;

/** A definitive saga failure (declined payment, order no longer payable): fail now, compensate, no retry. */
final class SagaStepRejected extends RuntimeException {}
