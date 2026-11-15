<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\Chat\Events\ConversationClaimed;
use Modules\Chat\Events\ConversationClosed;
use Modules\Chat\Events\MessagePersisted;
use Modules\Chat\Listeners\RecordChatMetrics;
use Tests\Support\MetricsTesting;

beforeEach(function (): void {
    config(['shared.metrics.enabled' => true, 'shared.metrics.driver' => 'memory']);
});

it('is wired to the three public Chat events', function (): void {
    $raw = Event::getRawListeners();

    expect($raw[MessagePersisted::class] ?? [])->toContain(RecordChatMetrics::class.'@messagePersisted')
        ->and($raw[ConversationClaimed::class] ?? [])->toContain(RecordChatMetrics::class.'@conversationClaimed')
        ->and($raw[ConversationClosed::class] ?? [])->toContain(RecordChatMetrics::class.'@conversationClosed');
});

it('counts persisted messages by sender role only', function (): void {
    $listener = app(RecordChatMetrics::class);
    $listener->messagePersisted(new MessagePersisted('conv-1', 'msg-1', 1, 'user-1', 'customer'));
    $listener->messagePersisted(new MessagePersisted('conv-1', 'msg-2', 2, 'user-2', 'support_agent'));
    $listener->messagePersisted(new MessagePersisted('conv-2', 'msg-3', 1, 'user-1', 'customer'));

    $body = MetricsTesting::body();
    expect(MetricsTesting::sample('aghigh_chat_messages_total{sender_role="customer"}'))->toBe(2.0)
        ->and(MetricsTesting::sample('aghigh_chat_messages_total{sender_role="support_agent"}'))->toBe(1.0)
        ->and($body)->not->toContain('conv-')->and($body)->not->toContain('user-')->and($body)->not->toContain('msg-');
});

it('counts claims without any label and closes by the closing role', function (): void {
    $listener = app(RecordChatMetrics::class);
    $listener->conversationClaimed(new ConversationClaimed('conv-1', 'agent-9'));
    $listener->conversationClaimed(new ConversationClaimed('conv-2', 'agent-9'));
    $listener->conversationClosed(new ConversationClosed('conv-1', 'agent-9', 'support_agent'));
    $listener->conversationClosed(new ConversationClosed('conv-2', 'user-3', 'Customer'));

    expect(MetricsTesting::sample('aghigh_chat_claims_total'))->toBe(2.0)
        ->and(MetricsTesting::sample('aghigh_chat_closes_total{closed_by_role="support_agent"}'))->toBe(1.0)
        ->and(MetricsTesting::sample('aghigh_chat_closes_total{closed_by_role="customer"}'))->toBe(1.0)
        ->and(MetricsTesting::body())->not->toContain('agent-9');
});

it('bounds a hostile role value to a short lower-case identifier', function (): void {
    app(RecordChatMetrics::class)->messagePersisted(new MessagePersisted('c', 'm', 1, 'u', 'Admin "root"; DROP'.str_repeat('x', 100)));

    $body = MetricsTesting::body();
    expect($body)->toContain('sender_role="admin_root_drop')->and($body)->not->toContain('"root"');
    preg_match('/sender_role="([^"]*)"/', $body, $match);
    expect(strlen($match[1]))->toBeLessThanOrEqual(40);
});
