<?php

declare(strict_types=1);

namespace Modules\Shared\ErrorReporting;

use Illuminate\Foundation\Configuration\Exceptions;
use Sentry\Laravel\Integration;

/**
 * Keeps the vendor import out of bootstrap/app.php (only Modules\Shared may reference Sentry\). Adds Sentry's
 * `reportable` hook: it only REPORTS (5xx and job failures, filtered and scrubbed by SentryScrubber), it does not
 * render, so ProblemDetails::render keeps producing every API error. With an empty DSN the SDK is a no-op.
 */
final class ErrorReporting
{
    public static function register(Exceptions $exceptions): void
    {
        Integration::handles($exceptions);
    }
}
