# Payments (Task 6)

## Contracts
`Modules\Payment\Contracts\PaymentCharger` (charge, refund) and `PaymentLedger` (status, statuses).
`payments.order_id` is UNIQUE; the gateway idempotency key is the order id (`refund:<order id>` for refunds).
Call them outside database transactions.

## FakeGateway (the only gateway)
| Setting | Default | Meaning |
|---|---|---|
| `PAYMENT_FAKE_LATENCY_MIN_MS` / `_MAX_MS` | 50 / 250 | uniform latency per call |
| `PAYMENT_FAKE_FAILURE_RATE` | 0.02 | share of declines |
| `PAYMENT_FAKE_TIMEOUT_RATE` | 0.01 | share of `GatewayTimeout`, half of them after capturing |
| `PAYMENT_FAKE_TIMEOUT_MS` | 3000 | how long a simulated timeout blocks |
| `PAYMENT_FAKE_SEED` | empty | same seed = same decisions per (order, attempt), whatever the worker interleaving |

Magic amounts (exact `amount_minor`): **402** declined, **504** always times out (never captured),
**408** times out after capturing on attempt 1 and replays the capture afterwards.
The first answer per key is stored on the `state` Redis with SETNX (an external ledger): a repeated key never
captures twice. Tests run with zero latency and zero rates (phpunit.xml).

## Webhook: `POST /api/v1/payments/webhook`
Not behind `auth:api`; authenticated only by the signature.
```
X-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "{t}.{raw body}")>[,v1=<signature with the other secret>]
```
- `|now - t| <= PAYMENT_WEBHOOK_TOLERANCE_SECONDS` (300); every `v1` is checked with `hash_equals` against
  `PAYMENT_WEBHOOK_SECRET` and `PAYMENT_WEBHOOK_SECRET_PREVIOUS` (rotation: deploy the new secret as current and
  the old one as previous, switch the sender, then clear previous).
- Replay: SETNX `payment:webhook:replay:sha256("{t}.{body}")` on `state`, TTL tolerance + 60 s; the same signed
  request twice is rejected. A failed processing attempt removes the marker so the identical retry can pass.
- Body: JSON `{"id": "<event id>", "type": "charge.succeeded|charge.failed|refund.succeeded|...", "data": {"order_id": "<uuid>", ...}}`.
  `payment_webhook_events.event_id` is UNIQUE: a known event answers `200 {"received": true, "duplicate": true}`.
- Any failure: `401 application/problem+json`, identical to every other 401 (no hint which check failed); the reason
  goes to the `security` log only.

Sign a test request:
```bash
BODY='{"id":"evt_1","type":"charge.succeeded","data":{"order_id":"<uuid>","reference":"ch_1"}}'
T=$(date +%s); SIG=$(printf '%s.%s' "$T" "$BODY" | openssl dgst -sha256 -hmac "$PAYMENT_WEBHOOK_SECRET" | awk '{print $2}')
curl -i -X POST http://localhost:8000/api/v1/payments/webhook -H "Content-Type: application/json" -H "X-Signature: t=$T,v1=$SIG" -d "$BODY"
```
