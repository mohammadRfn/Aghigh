<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\IdentityProblem;
use Modules\Identity\Services\Sessions;

[, $raw, $directory, $index] = $argv;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Start barrier: report ready, then spin until the parent releases everyone.
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 120;
while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
    usleep(500);
}

try {
    app(Sessions::class)->rotate($raw, Request::create('/'));
    echo json_encode(['status' => 200]);
} catch (IdentityProblem $problem) {
    echo json_encode(['status' => $problem->problemStatus(), 'error' => $problem->problemType()]);
}
