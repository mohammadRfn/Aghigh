<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks sensitive keys (any depth, case-insensitive substring match) and bearer/JWT-looking strings in the
 * message, context and extra of every record. The key list and the rules live in SensitiveKeys, shared with the
 * Sentry scrubber (Task 9) so logs and error reports can never disagree about what is secret.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = SensitiveKeys::MASK;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SensitiveKeys::redactString($record->message),
            context: SensitiveKeys::redactArray($record->context),
            extra: SensitiveKeys::redactArray($record->extra),
        );
    }
}
