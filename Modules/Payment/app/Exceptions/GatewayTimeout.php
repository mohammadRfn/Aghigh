<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use Modules\Payment\Contracts\GatewayUnavailable;
use RuntimeException;

final class GatewayTimeout extends RuntimeException implements GatewayUnavailable {}
