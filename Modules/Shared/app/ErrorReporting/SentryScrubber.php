<?php

declare(strict_types=1);

namespace Modules\Shared\ErrorReporting;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Modules\Shared\Contracts\ProblemException;
use Modules\Shared\Logging\SensitiveKeys;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * `before_send` for the Sentry SDK (GlitchTip). A static callable, referenced from config/sentry.php as
 * [SentryScrubber::class, 'beforeSend'] because a closure there breaks `config:cache`.
 *
 * 1. Drops client errors: ProblemException below 500, 4xx HttpExceptions, validation/auth/not-found. Only 5xx and
 *    job failures reach GlitchTip, so it is never flooded by the API's normal problem+json answers.
 * 2. Scrubs with the SAME key list as the log redactor (SensitiveKeys): request body and query string are removed
 *    outright, headers/cookies/extra/contexts/tags/breadcrumbs/exception messages go through the shared redaction.
 * 3. Tags the event with the request_id from Context (set by AssignRequestId, propagated into jobs).
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof Throwable && self::isClientError($exception)) {
            return null;
        }

        self::scrub($event);
        self::tagRequestId($event);

        return $event;
    }

    public static function isClientError(Throwable $error): bool
    {
        if ($error instanceof ProblemException) {
            return $error->problemStatus() < 500;
        }
        if ($error instanceof HttpExceptionInterface) {
            return $error->getStatusCode() < 500;
        }

        return $error instanceof ValidationException
            || $error instanceof AuthenticationException
            || $error instanceof AuthorizationException
            || $error instanceof ModelNotFoundException;
    }

    public static function scrub(Event $event): void
    {
        $request = $event->getRequest();
        if ($request !== []) {
            unset($request['data'], $request['cookies'], $request['query_string']);
            if (isset($request['url']) && is_string($request['url'])) {
                $request['url'] = strtok($request['url'], '?') ?: $request['url'];
            }
            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = SensitiveKeys::redactArray($request['headers']);
            }
            if (isset($request['env']) && is_array($request['env'])) {
                unset($request['env']['REMOTE_ADDR']);
                $request['env'] = SensitiveKeys::redactArray($request['env']);
            }
            $event->setRequest(SensitiveKeys::redactArray($request));
        }

        $event->setExtra(SensitiveKeys::redactArray($event->getExtra()));

        $tags = [];
        foreach (SensitiveKeys::redactArray($event->getTags()) as $key => $value) {
            $tags[(string) $key] = is_scalar($value) ? (string) $value : SensitiveKeys::MASK;
        }
        $event->setTags($tags);

        foreach ($event->getContexts() as $name => $context) {
            if (is_array($context)) {
                $event->setContext((string) $name, SensitiveKeys::redactArray($context));
            }
        }

        $event->setBreadcrumb(array_map(static function (Breadcrumb $crumb): Breadcrumb {
            $crumb = $crumb->withMessage(SensitiveKeys::redactString((string) $crumb->getMessage()));
            foreach (SensitiveKeys::redactArray($crumb->getMetadata()) as $key => $value) {
                $crumb = $crumb->withMetadata((string) $key, $value);
            }

            return $crumb;
        }, $event->getBreadcrumbs()));

        foreach ($event->getExceptions() as $bag) {
            $bag->setValue(SensitiveKeys::redactString($bag->getValue()));
        }

        $message = $event->getMessage();
        if ($message !== null) {
            $event->setMessage(SensitiveKeys::redactString($message));
        }

        $user = $event->getUser();
        if ($user !== null) {
            $event->setUser($user->getId() !== null ? new UserDataBag($user->getId()) : null); // id only, no email/IP
        }
    }

    private static function tagRequestId(Event $event): void
    {
        try {
            $requestId = Context::get('request_id');
        } catch (Throwable) {
            return;
        }
        if (is_string($requestId) && $requestId !== '') {
            $event->setTag('request_id', $requestId);
        }
    }
}
