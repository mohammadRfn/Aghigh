// Task 10: a steady mixed workload at a constant arrival rate (catalog reads, orders, /me, own orders).
// Shared by soak (long, moderate) and metrics-overhead (short, identical load for every variant).
// The first WARMUP seconds are tagged phase=warmup and excluded from thresholds.
import http from 'k6/http';
import exec from 'k6/execution';
import { VARIANT, baseOptions, envInt, envSeconds } from './config.js';
import { loginPool, sessionPicker } from './auth.js';
import { loadThresholds, mergeThresholds, httpPhase, materialize } from './thresholds.js';
import { makeHandleSummary } from './summary.js';
import { buildCatalogQueries, mixedIteration, productIdsFromEnv } from './workload.js';

export function steadyScenario(spec) {
  const variant = VARIANT;
  const RATE = envInt('RATE', spec.rate);
  const WARMUP = envSeconds('WARMUP', spec.warmup);
  const DURATION = envSeconds('DURATION', spec.duration);
  const ORDER_PCT = envInt('ORDER_PCT', spec.orderPct);
  const USERS = envInt('USERS', spec.users);
  const VUS = envInt('PREALLOCATED_VUS', spec.vus);
  const MAX_VUS = envInt('MAX_VUS', spec.maxVus);
  const t = loadThresholds(spec.scenario, variant);

  http.setResponseCallback(http.expectedStatuses(200, 201, 409));

  const options = baseOptions({
    scenarios: {
      steady: {
        executor: 'constant-arrival-rate',
        rate: RATE,
        timeUnit: '1s',
        duration: `${WARMUP + DURATION}s`,
        preAllocatedVUs: VUS,
        maxVUs: MAX_VUS,
        gracefulStop: '30s',
      },
    },
    thresholds: mergeThresholds(
      t.performance, t.assertions, httpPhase('main'), httpPhase('warmup'),
      materialize('counter', ['lt_http_429', 'lt_http_5xx', 'lt_auth_failures', 'lt_auth_refreshes', 'lt_auth_relogins']),
    ),
  });

  function setup() {
    if (USERS < MAX_VUS) throw new Error(`${spec.scenario}: USERS (${USERS}) must be >= MAX_VUS (${MAX_VUS}) so every VU owns its own accounts`);
    const pool = loginPool(USERS, { start: 1 + envInt('USER_OFFSET', 0) });
    const built = buildCatalogQueries(20);
    return { started_at: new Date().toISOString(), pool, queries: built.queries, products: productIdsFromEnv(), params: { query_count: built.queries.length } };
  }

  function run(data) {
    const phase = (Date.now() - exec.scenario.startTime) / 1000 < WARMUP ? 'warmup' : 'main';
    mixedIteration(data, sessionPicker(data.pool, MAX_VUS), phase, exec.scenario.iterationInTest, ORDER_PCT);
  }

  const handleSummary = makeHandleSummary({
    scenario: spec.scenario,
    variant,
    thresholdMeta: t.meta,
    assertions: t.assertions,
    phases: { main: DURATION, warmup: WARMUP },
    params: Object.assign(
      { rate_per_second: RATE, warmup_seconds: WARMUP, duration_seconds: DURATION, order_pct: ORDER_PCT, users: USERS, max_vus: MAX_VUS, products: productIdsFromEnv().length },
      spec.params || {},
    ),
    notes: spec.notes || [],
    derive: spec.derive,
  });

  return { options, setup, run, handleSummary };
}
