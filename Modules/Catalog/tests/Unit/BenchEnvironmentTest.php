<?php

declare(strict_types=1);

use Modules\Catalog\Support\BenchEnvironment;

it('uses an explicit environment allowlist', function (string $environment, bool $expected): void {
    expect(BenchEnvironment::enabled($environment))->toBe($expected);
})->with([
    ['local', true], ['testing', true], ['loadtest', true],
    ['production', false], ['staging', false], ['', false], ['preview', false], ['LOCAL', false],
]);
