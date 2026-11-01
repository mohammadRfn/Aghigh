<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
[$script, $directory, $worker, $counter] = $argv;
// A request must exist before bootstrap: providers resolve UrlGenerator during boot.
$request = Request::create('/api/v1/products?per_page=5', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);
$kernel->bootstrap();
$connection = (string) config('database.default');
if ($connection !== 'pgsql' || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
    fwrite(STDERR, 'Refusing to run catalog test worker outside a PostgreSQL _test database.');
    exit(2);
}
DB::listen(static function (QueryExecuted $event) use ($counter): void {
    if (str_contains(strtolower($event->sql), 'from "products"')) {
        Cache::store('state')->increment($counter);
        usleep(150000); // Deliberate test work to widen the cold race; not a latency result.
    }
});
touch($directory.'/ready-'.$worker);
$deadline = microtime(true) + 75;
while (! file_exists($directory.'/go')) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Catalog worker barrier timed out.');
        exit(3);
    }
    usleep(10000);
}
$response = $kernel->handle($request);
$body = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'ids' => array_column($body['data'] ?? [], 'id')], JSON_THROW_ON_ERROR).PHP_EOL;
