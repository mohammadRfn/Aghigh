<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\Shared\Logging\SwallowHandlerFailures;
use Monolog\Handler\StreamHandler;

it('does not let an unwritable log stream break the caller', function (): void {
    // The parent of this path is a file, so the stream can never be opened (also true when running as root).
    $channel = [
        'driver' => 'monolog',
        'level' => 'debug',
        'handler' => StreamHandler::class,
        'handler_with' => ['stream' => __FILE__.'/app.jsonl'],
    ];
    config([
        'logging.channels.broken_plain' => $channel,
        'logging.channels.broken_safe' => $channel + ['tap' => [SwallowHandlerFailures::class]],
    ]);

    // Control: without the tap the failure surfaces, so the test proves the tap is what saves the request.
    expect(fn () => Log::channel('broken_plain')->error('boom'))->toThrow(UnexpectedValueException::class);

    Log::channel('broken_safe')->error('boom');

    expect(true)->toBeTrue();
});

it('applies the tap to every JSON channel', function (): void {
    foreach (['app', 'security', 'audit', 'slow_query'] as $name) {
        expect(config("logging.channels.{$name}.tap"))->toBe([SwallowHandlerFailures::class]);
    }
});
