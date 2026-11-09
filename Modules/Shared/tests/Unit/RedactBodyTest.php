<?php

declare(strict_types=1);

use Modules\Shared\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

it('redacts message bodies (Task 8: `body` is a sensitive key)', function (): void {
    $record = new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'chat', [
        'body' => 'سلام، شماره کارت من ...',
        'request_body' => ['body' => 'nested'],
        'meta' => ['message_body' => 'x', 'seq' => 3],
    ]);

    $out = (new RedactSensitiveData)($record);

    expect($out->context['body'])->toBe(RedactSensitiveData::MASK)
        ->and($out->context['request_body'])->toBe(RedactSensitiveData::MASK)
        ->and($out->context['meta']['message_body'])->toBe(RedactSensitiveData::MASK)
        ->and($out->context['meta']['seq'])->toBe(3);
});
