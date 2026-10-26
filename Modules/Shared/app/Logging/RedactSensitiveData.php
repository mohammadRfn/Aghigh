<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks sensitive keys (any depth, case-insensitive substring match) and
 * bearer/JWT-looking strings in the message, context and extra of every record.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[REDACTED]';

    private const MAX_DEPTH = 8;

    /** @var list<string> */
    private const FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie',
        'api_key', 'apikey', 'jwt', 'card_number', 'cvv', 'signature', 'private_key', 'otp',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redact(array $data, int $depth = 0): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = $depth >= self::MAX_DEPTH ? self::MASK : $this->redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = $this->redactString($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function redactString(string $value): string
    {
        $value = preg_replace('/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 '.self::MASK, $value) ?? $value;

        return preg_replace('/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', self::MASK, $value) ?? $value;
    }
}