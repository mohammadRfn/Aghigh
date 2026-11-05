<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Exceptions\WebhookRejected;
use Modules\Payment\Webhooks\PaymentWebhooks;
use Modules\Payment\Webhooks\WebhookVerifier;
use Throwable;

#[Group('Payments')]
final class WebhookController
{
    /**
     * Payment gateway webhook. Authenticated ONLY by `X-Signature: t=<unix>,v1=<hex hmac-sha256>` over
     * `"{t}.{raw body}"` (5 minute tolerance, two secrets during rotation). Not behind the JWT guard.
     * Any verification failure is the same 401 problem+json. A known event id answers 200 with
     * `duplicate: true` and changes nothing.
     */
    public function __invoke(Request $request, WebhookVerifier $verifier, PaymentWebhooks $webhooks): JsonResponse
    {
        try {
            $verified = $verifier->verify((string) $request->headers->get('X-Signature', ''), $request->getContent(), time());
        } catch (WebhookRejected $rejected) {
            Log::channel('security')->warning('payment_webhook_rejected', ['reason' => $rejected->reason, 'ip' => $request->ip()]);
            throw $rejected;
        }

        try {
            $applied = $webhooks->handle($verified['event'], $verified['signed_at']);
        } catch (Throwable $error) {
            // Let the gateway's identical retry through: the replay marker belonged to a failed attempt.
            $verifier->forgetReplay($verified['replay_key']);
            throw $error;
        }

        return new JsonResponse(['received' => true, 'duplicate' => ! $applied]);
    }
}
