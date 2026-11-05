# ADR 0007: Order payment saga (orchestrated, at-least-once, idempotent steps)

Status: accepted (Task 6). Numbered 0007 because the code already refers to ADR 0005 (stock strategies) and
ADR 0006 (POST /orders transaction).

## Context
POST /orders reserves stock in one short transaction (ADR 0006). Paying needs an external, slow, fallible
gateway; confirming needs the stock committed (reserved -> sold); the buyer must be told. The app runs behind
PgBouncer in transaction mode, so a transaction must never wait on the network.

## Decision
1. **Saga, not one transaction.** A single transaction around the gateway call would pin a PgBouncer server
   connection for the gateway's latency (and its timeouts), and it still could not roll back money already
   captured by the provider. Each step is its own short local transaction; consistency comes from compensation.
2. **Orchestration with `Bus::chain()`** on the `orders` queue, dispatched inside the POST /orders transaction
   with `afterCommit` (nothing is pushed if the order rolls back):
   `ChargePayment -> ConfirmOrder -> NotifyOrderConfirmed`, `->catch(new CompensateOnChainFailure($orderId))`.
   Chosen over choreography (outbox events driving the next step) because the whole flow and its compensation
   live in one place (`Modules\Ordering\Services\OrderSaga`), are testable with `Bus::fake()` and the sync
   queue, and need no extra state machine for "which event comes next".
3. **Pivot = ConfirmOrder.** One transaction: `confirmed_at` (conditional on paid + not confirmed), outbox
   `OrderConfirmed`, `StockReservations::commit()`. Before the pivot every failure is compensated; after it
   nothing is (a lost e-mail never cancels a paid, confirmed order).
4. **Compensation rules** (`CompensateOrder`, state-driven and idempotent):
   - confirmed (or fulfilled) order: do nothing;
   - pending/reserved/paid order: `transition(cancelled)` + `StockReservations::release()` in ONE transaction,
     order row first (its conditional UPDATE decides any race with expiry or a late payment);
   - expired/cancelled order still holding stock: release it (heals partial failures);
   - payment `succeeded` or `pending` (ambiguous timeout): `RefundPayment`. Payment reconciles a pending
     payment with what the gateway recorded for the idempotency key: captured -> refund, never captured -> void.
   - compensation that keeps failing is a human's job: `failed()` logs `order_compensation_failed` on the
     audit channel and `orders:verify-invariants` lists the order.
   ReleaseStock and CancelOrder are one job step on purpose: as two jobs there is a window where a paid order
   has no stock or a reserved order already lost its stock.
5. **Two triggers, one effect.** Both the chain `catch` callback and every saga job's `failed()` dispatch
   `CompensateOrder`; it is `ShouldBeUnique` per order and idempotent, so duplicates collapse.
6. **Reservation expiry is order-first** (`ReservationExpiry`): reserved -> expired and
   `StockReservations::expire()` in one transaction per order, rows `FOR UPDATE SKIP LOCKED`. Inventory's
   reservation-first `releaseExpired()` is not used: it cannot see that an order was just paid. The sweep is also
   the safety net for a saga whose push was lost after the commit.

## At-least-once delivery, and why every step is idempotent
Redis queues redeliver: a worker killed after the side effect but before the ack, a `retry_after` expiry, an
operator retry, a lock that expired. The design assumes every job runs 1..n times, possibly in parallel:
- ChargePayment: returns early when the order is already paid; `payments.order_id` is UNIQUE; payment status
  changes are `UPDATE ... WHERE status = 'pending'`; the gateway call carries the order id as idempotency key
  (the fake gateway stores the first answer per key with SETNX). Parallel duplicates: one payment row, one capture.
- ConfirmOrder: `UPDATE orders SET confirmed_at ... WHERE status = 'paid' AND confirmed_at IS NULL` gates the
  stock commit; reservations flip with `WHERE status = 'active'`. Parallel duplicates commit stock once.
- NotifyOrderConfirmed: `notified_at` claim; the mail job is `ShouldBeUnique`. Push-then-mark = at-least-once
  mail (a duplicate mail is preferred over a lost one).
- CompensateOrder / RefundPayment / ExpireReservations / RelayOutbox: conditional updates and unique jobs.
The per-order `WithoutOverlapping` lock (on the state Redis) only reduces contention; correctness never
depends on it (`Modules/Ordering/tests/Concurrency/SagaQueueTest.php` runs job bodies in parallel without it).

## Consequences
- An order is `reserved` until the saga runs; clients poll `GET /orders/{id}` (or Task 8 pushes the change).
- Refund failures need monitoring (audit log, verify-invariants). Fulfillment stays a separate admin action.
- Tests: Feature tests use the sync queue; POST /orders tests that do not care about payment disable the saga.
