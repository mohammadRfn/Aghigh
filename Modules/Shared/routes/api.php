<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Shared\Support\UnsafeEnvironment;

// Served under /api/v1 (see this module's RouteServiceProvider).

Route::get('shared/ping', fn () => response()->json([
    'module' => 'Shared',
    'status' => 'ok',
]))->name('shared.ping');

// Task 7: local/testing/loadtest only. Shows which client IP Laravel resolved (through TrustProxies) so the
// trusted-proxy setup can be verified against the real compose stack: scripts/verify-trusted-proxy.sh.
if (UnsafeEnvironment::allows(app()->environment())) {
    Route::get('_debug/client-ip', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'remote_addr' => $request->server('REMOTE_ADDR'),
        'from_trusted_proxy' => $request->isFromTrustedProxy(),
    ]))->name('shared.debug.client-ip');
}
