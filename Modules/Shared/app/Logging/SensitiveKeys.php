<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

/**
 * THE list of sensitive key fragments and the redaction rules, shared by the Monolog processor (RedactSensitiveData)
 * and the Sentry/GlitchTip scrubber (ErrorReporting\SentryScrubber). Add a fragment here and both paths get it.
 * Matching: case-insensitive substring on the key, any depth; bearer/JWT-looking strings are masked in values.
 */
final class SensitiveKeys
{
    public const MASK = '[REDACTED]';

    public const MAX_DEPTH = 8;

    /** @var list<string> */
    public const FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie',
        'api_key', 'apikey', 'jwt', 'card_number', 'cvv', 'signature', 'private_key', 'otp',
        'body', // Task 8: chat message bodies (and any request/response body) are never logged
    ];

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = $depth >= self::MAX_DEPTH ? self::MASK : self::redactArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = self::redactString($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function redactString(string $value): string
    {
        $value = preg_replace('/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 '.self::MASK, $value) ?? $value;

        return preg_replace('/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', self::MASK, $value) ?? $value;
    }
}
