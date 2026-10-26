<?php

declare(strict_types=1);

use Modules\Shared\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

$record = static fn (string $message, array $context = [], array $extra = []): LogRecord => (new RedactSensitiveData)(
    new LogRecord(new DateTimeImmutable, 'app', Level::Info, $message, $context, $extra),
);

it('masks sensitive keys at any depth, case-insensitively', function () use ($record): void {
    $out = $record('login', [
        'email' => 'a@b.c',
        'Password' => 'hunter2',
        'headers' => ['Authorization' => 'Bearer abcdefgh1234', 'accept' => 'json'],
        'nested' => ['refresh_token' => 'x', 'ok' => 1],
    ], ['api_key' => 'k']);

    expect($out->context)->toBe([
        'email' => 'a@b.c',
        'Password' => '[REDACTED]',
        'headers' => ['Authorization' => '[REDACTED]', 'accept' => 'json'],
        'nested' => ['refresh_token' => '[REDACTED]', 'ok' => 1],
    ])->and($out->extra)->toBe(['api_key' => '[REDACTED]']);
});

it('masks bearer tokens and JWTs inside strings', function () use ($record): void {
    $jwt = 'eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0.sig';
    $out = $record('header was Bearer abcdefgh12345', ['note' => "got {$jwt} here"]);

    expect($out->message)->toBe('header was Bearer [REDACTED]')
        ->and($out->context['note'])->toBe('got [REDACTED] here');
});

it('leaves harmless data untouched', function () use ($record): void {
    $out = $record('ok', ['order_id' => 7, 'items' => [1, 2]]);

    expect($out->context)->toBe(['order_id' => 7, 'items' => [1, 2]]);
});