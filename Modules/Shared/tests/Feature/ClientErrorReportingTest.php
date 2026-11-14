<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Modules\Shared\Contracts\ProblemException;

function problemWithStatus(int $status): ProblemException
{
    return new class($status) extends RuntimeException implements ProblemException
    {
        public function __construct(private readonly int $status)
        {
            parent::__construct('problem');
        }

        public function problemStatus(): int
        {
            return $this->status;
        }

        public function problemType(): string
        {
            return 'test-problem';
        }

        public function problemExtensions(): array
        {
            return [];
        }
    };
}

it('does not report client-error problems but still reports server faults', function (): void {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(problemWithStatus(401)))->toBeFalse()
        ->and($handler->shouldReport(problemWithStatus(409)))->toBeFalse()
        ->and($handler->shouldReport(problemWithStatus(429)))->toBeFalse()
        ->and($handler->shouldReport(problemWithStatus(503)))->toBeTrue()
        ->and($handler->shouldReport(new RuntimeException('boom')))->toBeTrue();
});
