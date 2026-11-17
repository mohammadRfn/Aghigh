// Task 10, scenario 2: order flood. USERS different customers buy the SAME low-stock product at the same instant
// (one order each, quantity 1), with Idempotency-Key retries mixed in (REPLAY_PCT % of the users resend their key,
// half of them concurrently, half right after the first answer).
// Variants (LT_VARIANT = STOCK_STRATEGY of the recreated app containers): naive | atomic | locking.
//   atomic, locking  exactly min(stock, users) keys end in an order, every other key ends in 409 out-of-stock;
//                    a replay returns the original order and never creates a second one. Asserted here AND by
//                    orders:verify-invariants (after-run.sh).
//   naive            is EXPECTED to oversell. Nothing is asserted here; after-run.sh --expect-violations asserts that
//                    orders:verify-invariants reports violations and records the count.
// run-scenario.sh sets PAYMENT_FAKE_FAILURE_RATE=0 / PAYMENT_FAKE_TIMEOUT_RATE=0 for this scenario: a failed payment
// releases stock during the flood and "orders == stock" would stop being exact for reasons unrelated to the race.
import http from 'k6/http';
import exec from 'k6/execution';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { VARIANT, baseOptions, envInt, envStr, randomId } from '../lib/config.js';
import { track } from '../lib/checks.js';
import { loginPool } from '../lib/auth.js';
import { loadThresholds, mergeThresholds, httpPhase, materialize } from '../lib/thresholds.js';
import { makeHandleSummary } from '../lib/summary.js';
import { classifyOrder, countOrder, orderIdOf, orderRequest, ordersCreated, ordersReplayed, ordersOutOfStock, ordersInProgress, ordersOther } from '../lib/workload.js';

const variant = VARIANT === 'default' ? envStr('LT_ENV_STOCK_STRATEGY', 'atomic') : VARIANT;
if (['naive', 'atomic', 'locking'].indexOf(variant) === -1) throw new Error(`order-flood: unknown variant "${variant}" (naive | atomic | locking)`);

const USERS = envInt('USERS', 150);
const USER_OFFSET = envInt('USER_OFFSET', 0); // first account = loadtest-(offset+1)
const STOCK = envInt('LT_STOCK', 0); // set on the product by run-scenario.sh (inventory:set-stock)
const PRODUCT = envStr('LT_PRODUCT_ID', '');
const REPLAY_PCT = envInt('REPLAY_PCT', 30);
const GATE_MS = envInt('GATE_MS', 3000); // every VU waits for the same instant, then fires

const keysCreated = new Counter('lt_order_keys_created'); // Idempotency-Keys that ended in an order
const keysRejected = new Counter('lt_order_keys_rejected'); // keys that ended in 409 out-of-stock
const keysOther = new Counter('lt_order_keys_other');
const replaysSent = new Counter('lt_order_replays_sent');
const replayMismatch = new Counter('lt_orders_replay_mismatch'); // a replay named a different order than the original
const replayBodyDiff = new Counter('lt_orders_replay_body_diff'); // replay body not byte-identical (recorded, not asserted)
const doubleCreated = new Counter('lt_orders_double_created'); // one key, two non-replayed 2xx answers
const everyCounter = [keysCreated, keysRejected, keysOther, replaysSent, replayMismatch, replayBodyDiff, doubleCreated, ordersCreated, ordersReplayed, ordersOutOfStock, ordersInProgress, ordersOther];

const t = loadThresholds('order-flood', variant);
const expectedCreated = Math.min(STOCK, USERS);
// Exact counts only for the safe strategies; the numbers come from the run parameters, not from a measurement.
const exact = variant === 'naive' || STOCK <= 0 ? {} : {
  lt_order_keys_created: [`count==${expectedCreated}`],
  lt_order_keys_rejected: [`count==${USERS - expectedCreated}`],
};
const assertions = mergeThresholds(t.assertions, exact);

// 409 is a valid business answer here, so it must not count as a failed request.
http.setResponseCallback(http.expectedStatuses(200, 201, 409));

export const options = baseOptions({
  scenarios: {
    flood: { executor: 'per-vu-iterations', vus: USERS, iterations: 1, maxDuration: envStr('MAX_DURATION', '5m'), gracefulStop: '30s' },
  },
  thresholds: mergeThresholds(t.performance, assertions, httpPhase('main'), materialize('counter', ['lt_http_429', 'lt_http_5xx'])),
});

export function setup() {
  if (!PRODUCT) throw new Error('order-flood: LT_PRODUCT_ID is empty. Run it through `make k6-order-flood STRATEGY=...` (it picks a product and sets its stock).');
  const users = loginPool(USERS, { start: 1 + USER_OFFSET });
  return { started_at: new Date().toISOString(), users, go_at: Date.now() + GATE_MS };
}

function settle(responses, tags) {
  const kinds = responses.map(classifyOrder);
  kinds.forEach((kind, i) => {
    countOrder(kind, tags);
    track(responses[i], [200, 201, 409], tags);
  });
  const originals = [];
  const replays = [];
  kinds.forEach((kind, i) => {
    if (kind === 'created') originals.push(responses[i]);
    if (kind === 'replayed') replays.push(responses[i]);
  });

  if (originals.length > 1) doubleCreated.add(1, tags);
  const ids = originals.concat(replays).map(orderIdOf);
  if (ids.length > 0 && (ids.indexOf(null) !== -1 || ids.some((id) => id !== ids[0]))) replayMismatch.add(1, tags);
  if (originals.length === 1) {
    for (const replay of replays) {
      if (replay.status !== originals[0].status) replayMismatch.add(1, tags);
      else if (replay.body !== originals[0].body) replayBodyDiff.add(1, tags);
    }
  }

  let outcome = 'other';
  if (originals.length + replays.length > 0) outcome = 'created';
  else if (kinds.indexOf('out_of_stock') !== -1 && kinds.every((kind) => kind === 'out_of_stock' || kind === 'in_progress')) outcome = 'rejected';
  if (outcome === 'created') keysCreated.add(1, tags);
  else if (outcome === 'rejected') keysRejected.add(1, tags);
  else keysOther.add(1, tags);
  check(outcome, { 'key ended in an order or in 409 out-of-stock': (o) => o !== 'other' }, tags);
  return outcome;
}

export default function (data) {
  const id = exec.vu.idInTest;
  const user = data.users[(id - 1) % data.users.length];
  const tags = { phase: 'main' };
  for (const counter of everyCounter) counter.add(0, tags); // every counter exists in the result even when it stays 0

  const wait = data.go_at - Date.now();
  if (wait > 0) sleep(wait / 1000);

  const spec = orderRequest(user.token, PRODUCT, randomId(32), 'main');
  const replaying = (id * 37) % 100 < REPLAY_PCT; // 37 is coprime with 100: an even REPLAY_PCT % share for any number of users
  const concurrent = replaying && Math.floor(id / 2) % 2 === 0;
  let responses;
  if (concurrent) {
    responses = http.batch([spec, spec]); // same key, same payload, at the same time
    replaysSent.add(1, tags);
  } else {
    responses = [http.post(spec.url, spec.body, spec.params)];
    if (replaying) {
      sleep(Math.random() * 0.2);
      responses.push(http.post(spec.url, spec.body, spec.params));
      replaysSent.add(1, tags);
    }
  }
  settle(responses, tags);
}

export const handleSummary = makeHandleSummary({
  scenario: 'order-flood',
  variant,
  thresholdMeta: t.meta,
  assertions,
  phases: { main: null },
  params: { users: USERS, user_offset: USER_OFFSET, stock: STOCK, product_id: PRODUCT || null, replay_pct: REPLAY_PCT, gate_ms: GATE_MS, expected_orders: variant === 'naive' ? null : expectedCreated },
  notes: variant === 'naive'
    ? ['naive is EXPECTED to oversell: the pass/fail of this run is the violation count asserted by after-run.sh --expect-violations.']
    : [],
  derive(result) {
    const c = result.metrics.counters;
    const created = c.lt_order_keys_created;
    result.metrics.derived = {
      stock: STOCK,
      users: USERS,
      keys_created: created,
      keys_rejected_out_of_stock: c.lt_order_keys_rejected,
      keys_other: c.lt_order_keys_other,
      replays_sent: c.lt_order_replays_sent,
      replays_answered_with_original: c.lt_orders_replayed,
      replay_mismatches: c.lt_orders_replay_mismatch,
      // k6's own view only (answers it received). The authoritative oversell check is orders:verify-invariants.
      orders_beyond_stock_seen_by_k6: typeof created === 'number' && STOCK > 0 ? Math.max(0, created - STOCK) : null,
    };
  },
});
