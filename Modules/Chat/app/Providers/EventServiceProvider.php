<?php

declare(strict_types=1);

namespace Modules\Chat\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Chat\Events\ConversationClaimed;
use Modules\Chat\Events\ConversationClosed;
use Modules\Chat\Events\MessagePersisted;
use Modules\Chat\Listeners\RecordChatMetrics;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        // Task 9: chat counters (after commit, ids and roles only).
        MessagePersisted::class => [RecordChatMetrics::class.'@messagePersisted'],
        ConversationClaimed::class => [RecordChatMetrics::class.'@conversationClaimed'],
        ConversationClosed::class => [RecordChatMetrics::class.'@conversationClosed'],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
