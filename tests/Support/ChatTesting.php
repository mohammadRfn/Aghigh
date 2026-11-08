<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Log;
use Modules\Identity\Contracts\CurrentUser;
use Modules\Identity\Models\User;
use Monolog\Handler\TestHandler;

/** Task 8 helpers. Lives in tests/Support so Chat tests never touch Identity internals directly. */
final class ChatTesting
{
    public const LOG_CHANNELS = ['app', 'security', 'audit', 'slow_query', 'stack', 'single', 'stderr', 'null'];

    public static function configure(): void
    {
        CatalogTesting::configure();
        config(['chat.state_driver' => config('chat.state_driver', 'memory')]);
    }

    /** @return array{id: string, token: string, role: string, actor: CurrentUser} */
    public static function user(string $role = 'customer'): array
    {
        $user = Actors::user($role);

        return $user + ['role' => $role, 'actor' => new CurrentUser($user['id'], $role, true)];
    }

    public static function key(): string
    {
        return 'cm_'.bin2hex(random_bytes(10));
    }

    public static function expiredToken(): string
    {
        $user = User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
        $past = now()->subHour()->timestamp;

        return IdentityTesting::jwt($user, ['iat' => $past - 600, 'nbf' => $past - 600, 'exp' => $past]);
    }

    /** Switch to a real (signing) reverb broadcaster so channel callbacks run, and re-register Chat's channels on it. */
    public static function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '100001',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('Modules/Chat/routes/channels.php');
    }

    /** Route every log channel to an in-memory Monolog TestHandler (no RedactSensitiveData: proves nothing logs bodies at all). */
    public static function captureLogs(): void
    {
        foreach (self::LOG_CHANNELS as $channel) {
            config(["logging.channels.{$channel}" => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
            Log::forgetChannel($channel);
        }
        config(['logging.default' => 'app']);
    }

    /** @return list<array{channel: string, message: string, context: array<mixed>}> */
    public static function records(): array
    {
        $out = [];
        foreach (self::LOG_CHANNELS as $channel) {
            foreach (Log::channel($channel)->getLogger()->getHandlers() as $handler) {
                if ($handler instanceof TestHandler) {
                    foreach ($handler->getRecords() as $record) {
                        $out[] = ['channel' => $channel, 'message' => $record->message, 'context' => $record->context];
                    }
                }
            }
        }

        return $out;
    }

    public static function logText(): string
    {
        return json_encode(self::records(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
