<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Modules\Shared\ErrorReporting\SentryScrubber;
use Modules\Shared\Exceptions\RateLimitExceeded;
use Modules\Shared\Logging\SensitiveKeys;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const SCRUB_BODY = 'super-secret-chat-body-7f3a';
const SCRUB_TOKEN = 'Bearer eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
const SCRUB_COOKIE = 'refresh_token=rt-9f8e7d6c5b4a';
const SCRUB_PASSWORD = 'hunter2-not-for-glitchtip';

function scrubbableEvent(): Event
{
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'http://localhost/api/v1/chat/messages?token=qs-token-1234',
        'method' => 'POST',
        'query_string' => 'token=qs-token-1234',
        'data' => ['body' => SCRUB_BODY, 'password' => SCRUB_PASSWORD],
        'cookies' => ['refresh_token' => 'rt-9f8e7d6c5b4a'],
        'headers' => ['Authorization' => [SCRUB_TOKEN], 'Cookie' => [SCRUB_COOKIE], 'Accept' => ['application/json']],
        'env' => ['REMOTE_ADDR' => '10.0.0.9'],
    ]);
    $event->setExtra(['payload' => ['card_number' => '4111111111111111'], 'note' => 'auth '.SCRUB_TOKEN]);
    $event->setTags(['api_key' => 'k-123', 'route' => 'chat.messages.store']);
    $event->setContext('job', ['secret' => 's3cr3t', 'queue' => 'orders']);
    $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'http', 'sent '.SCRUB_TOKEN, ['cookie' => SCRUB_COOKIE])]);
    $event->setMessage('login failed with '.SCRUB_TOKEN); // free text: only bearer/JWT patterns are masked, like the log redactor
    $event->setUser(new UserDataBag('user-1', 'someone@example.test', '10.0.0.9', 'someone'));

    return $event;
}

it('never lets a body, a token, a password or a cookie survive into an event', function (): void {
    $event = SentryScrubber::beforeSend(scrubbableEvent(), EventHint::fromArray(['exception' => new RuntimeException('boom '.SCRUB_TOKEN)]));

    expect($event)->not->toBeNull();
    $json = json_encode([
        'request' => $event->getRequest(),
        'extra' => $event->getExtra(),
        'tags' => $event->getTags(),
        'contexts' => $event->getContexts(),
        'breadcrumbs' => array_map(fn (Breadcrumb $b): array => [$b->getMessage(), $b->getMetadata()], $event->getBreadcrumbs()),
        'message' => $event->getMessage(),
        'user' => [$event->getUser()?->getId(), $event->getUser()?->getEmail(), $event->getUser()?->getIpAddress(), $event->getUser()?->getUsername()],
    ], JSON_THROW_ON_ERROR);

    foreach ([SCRUB_BODY, 'eyJhbGciOiJSUzI1NiJ9', SCRUB_COOKIE, 'rt-9f8e7d6c5b4a', SCRUB_PASSWORD, 'qs-token-1234', '4111111111111111', 's3cr3t', 'k-123', 'someone@example.test', '10.0.0.9'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect($json)->toContain(SensitiveKeys::MASK)
        ->and($event->getRequest())->not->toHaveKeys(['data', 'cookies', 'query_string'])
        ->and($event->getRequest()['url'])->toBe('http://localhost/api/v1/chat/messages')
        ->and($event->getTags()['route'])->toBe('chat.messages.store')
        ->and($event->getUser()?->getId())->toBe('user-1');
});

it('drops client errors (4xx, ProblemException below 500) and keeps server errors', function (): void {
    $drop = fn (Throwable $e) => SentryScrubber::beforeSend(Event::createEvent(), EventHint::fromArray(['exception' => $e]));

    expect($drop(new NotFoundHttpException))->toBeNull()
        ->and($drop(new HttpException(422)))->toBeNull()
        ->and($drop(new RateLimitExceeded('too-many-requests', ['Retry-After' => '5'])))->toBeNull() // 429 problem
        ->and($drop(new HttpException(503)))->not->toBeNull()
        ->and($drop(new RuntimeException('db down')))->not->toBeNull()
        ->and(SentryScrubber::beforeSend(Event::createEvent()))->not->toBeNull();
});

it('tags events with the request_id from Context', function (): void {
    Context::add('request_id', 'req-0123456789abcdef');

    $event = SentryScrubber::beforeSend(Event::createEvent(), null);

    expect($event?->getTags()['request_id'] ?? null)->toBe('req-0123456789abcdef');
});

it('uses the same key list as the log redactor', function (): void {
    $redacted = SensitiveKeys::redactArray(['Authorization' => 'x', 'nested' => ['cookie' => 'y', 'body' => 'z', 'ok' => 'fine']]);

    expect($redacted)->toBe(['Authorization' => SensitiveKeys::MASK, 'nested' => ['cookie' => SensitiveKeys::MASK, 'body' => SensitiveKeys::MASK, 'ok' => 'fine']]);
});
