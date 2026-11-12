<?php

declare(strict_types=1);

namespace Modules\Shared\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger as Monolog;

/**
 * Logging must never break a request: an unwritable stream (disk full, permissions, bad path) makes Monolog's
 * StreamHandler throw, which would turn a healthy request into a 500. Applied as a channel `tap` (a class name,
 * so `config:cache` keeps working). The failure is dropped; the app keeps serving.
 */
final class SwallowHandlerFailures
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $monolog->setHandlers([new WhatFailureGroupHandler($monolog->getHandlers())]);
    }
}
