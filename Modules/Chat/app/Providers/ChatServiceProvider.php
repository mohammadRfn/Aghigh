<?php

declare(strict_types=1);

namespace Modules\Chat\Providers;

use Illuminate\Contracts\Redis\Factory;
use InvalidArgumentException;
use Modules\Chat\Console\RebuildQueue;
use Modules\Chat\Console\RebuildUnread;
use Modules\Chat\Console\VerifyInvariants;
use Modules\Chat\State\ChatState;
use Modules\Chat\State\InMemoryChatState;
use Modules\Chat\State\RedisChatState;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ChatServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Chat';

    protected string $nameLower = 'chat';

    protected array $commands = [RebuildQueue::class, RebuildUnread::class, VerifyInvariants::class];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ChatState::class, function ($app): ChatState {
            return match ((string) config('chat.state_driver', 'redis')) {
                'redis' => new RedisChatState($app->make(Factory::class), (string) config('chat.state_connection', 'state')),
                'memory' => new InMemoryChatState,
                default => throw new InvalidArgumentException('CHAT_STATE_DRIVER must be redis|memory.'),
            };
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Chat owns its channels (guards => ['api']); registered after every provider booted, like withBroadcasting().
        $this->app->booted(function (): void {
            require module_path($this->name, 'routes/channels.php');
        });
    }
}
