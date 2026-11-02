<?php

declare(strict_types=1);

use Modules\Shared\Support\UnsafeEnvironment;

it('allows unsafe test aids only in local testing and loadtest', function (string $environment, bool $allowed): void {
    expect(UnsafeEnvironment::allows($environment))->toBe($allowed);
})->with([
    ['local', true], ['testing', true], ['loadtest', true],
    ['production', false], ['staging', false], ['', false], ['LOCAL', false],
]);
