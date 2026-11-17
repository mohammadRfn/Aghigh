// Task 10, scenario 3: spike. A steady base rate, a sudden SPIKE_FACTOR x jump (default 10x), then back to the base
// rate. Mixed traffic (catalog reads, orders, /me, own orders) so the queues get real saga jobs.
// Phases (tag `phase`): warmup (excluded) -> baseline -> spike -> recovery. Thresholds look at baseline and recovery;
// the spike phase is recorded without a budget. Every request is also tagged `win:<n>` (WINDOW-second slots since
// the scenario start) so the result has a p95 timeline and `seconds_back_under_p95_budget` after the spike ends.
// After the run, after-run.sh records `queue:drain-time --json` and the failed-job count.
// Limits to know (per IP, k6 is one IP): public-catalog and orders-ip, both times RATE_LIMIT_MULTIPLIER. The peak is
// RATE x SPIKE_FACTOR iterations/s; keep it under those or the spike measures the limiter (lt_http_429 shows it).
import http from 'k6/http';
import exec from 'k6/execution';
import { VARIANT, baseOptions, envInt, envSeconds } from '../lib/config.js';
import { loginPool, sessionPicker } from '../lib/auth.js';
import { loadThresholds, mergeThresholds, httpPhase, materialize, budgetOf } from '../lib/thresholds.js';
import { makeHandleSummary, timelineOf } from '../lib/summary.js';
import { buildCatalogQueries, mixedIteration, productIdsFromEnv } from '../lib/workload.js';

const variant = VARIANT;
const RATE = envInt('RATE', 8);
const FACTOR = envInt('SPIKE_FACTOR', 10);
const WARMUP = envSeconds('WARMUP', '20s');
const BASELINE = envSeconds('BASELINE', '30s');
const JUMP = envSeconds('SPIKE_RAMP', '3s'); // "sudden": the ramp to the peak
const SPIKE = envSeconds('SPIKE', '30s');
const DROP = envSeconds('SPIKE_DROP', '3s');
const RECOVERY = envSeconds('RECOVERY', '90s');
const WINDOW = envSeconds('WINDOW', '5s');
const ORDER_PCT = envInt('ORDER_PCT', 20);
const USERS = envInt('USERS', 300);
const VUS = envInt('PREALLOCATED_VUS', 100);
const MAX_VUS = envInt('MAX_VUS', 300);

const marks = { baseline: WARMUP, spike: WARMUP + BASELINE, recovery: WARMUP + BASELINE + JUMP + SPIKE + DROP };
const TOTAL = marks.recovery + RECOVERY;
const WINDOWS = Math.ceil(TOTAL / WINDOW) + 1;
const windowNames = [];
for (let i = 0; i < WINDOWS; i++) windowNames.push(`http_req_duration{win:${i}}`);

const t = loadThresholds('spike', variant);

http.setResponseCallback(http.expectedStatuses(200, 201, 409));

export const options = baseOptions({
  scenarios: {
    spike: {
      executor: 'ramping-arrival-rate',
      startRate: RATE,
      timeUnit: '1s',
      preAllocatedVUs: VUS,
      maxVUs: MAX_VUS,
      stages: [
        { target: RATE, duration: `${WARMUP + BASELINE}s` },
        { target: RATE * FACTOR, duration: `${JUMP}s` },
        { target: RATE * FACTOR, duration: `${SPIKE}s` },
        { target: RATE, duration: `${DROP}s` },
        { target: RATE, duration: `${RECOVERY}s` },
      ],
      gracefulStop: '30s',
    },
  },
  thresholds: mergeThresholds(
    t.performance, t.assertions,
    httpPhase('baseline'), httpPhase('spike'), httpPhase('recovery'), httpPhase('warmup'),
    materialize('trend', windowNames),
    materialize('rate', ['lt_unexpected_response{phase:spike}']),
    materialize('counter', ['lt_http_429', 'lt_http_5xx']),
  ),
});

export function setup() {
  if (USERS < MAX_VUS) throw new Error(`spike: USERS (${USERS}) must be >= MAX_VUS (${MAX_VUS}) so every VU owns its own accounts`);
  const pool = loginPool(USERS, { start: 1 + envInt('USER_OFFSET', 0) });
  const built = buildCatalogQueries(20);
  return { started_at: new Date().toISOString(), pool, queries: built.queries, products: productIdsFromEnv(), params: { query_count: built.queries.length } };
}

export default function (data) {
  const elapsed = (Date.now() - exec.scenario.startTime) / 1000;
  const phase = elapsed < marks.baseline ? 'warmup' : elapsed < marks.spike ? 'baseline' : elapsed < marks.recovery ? 'spike' : 'recovery';
  exec.vu.metrics.tags.win = String(Math.min(WINDOWS - 1, Math.floor(elapsed / WINDOW)));
  mixedIteration(data, sessionPicker(data.pool, MAX_VUS), phase, exec.scenario.iterationInTest, ORDER_PCT);
}

export const handleSummary = makeHandleSummary({
  scenario: 'spike',
  variant,
  mainPhase: 'baseline',
  thresholdMeta: t.meta,
  assertions: t.assertions,
  phases: { baseline: BASELINE, spike: JUMP + SPIKE + DROP, recovery: RECOVERY, warmup: WARMUP },
  params: {
    base_rate_per_second: RATE, spike_factor: FACTOR, peak_rate_per_second: RATE * FACTOR,
    warmup_seconds: WARMUP, baseline_seconds: BASELINE, spike_ramp_seconds: JUMP, spike_seconds: SPIKE, spike_drop_seconds: DROP,
    recovery_seconds: RECOVERY, window_seconds: WINDOW, order_pct: ORDER_PCT, users: USERS, max_vus: MAX_VUS,
    products: productIdsFromEnv().length,
  },
  notes: ['metrics.p50..p99/rps/error_rate describe the BASELINE phase; spike and recovery are under metrics.phases.'],
  derive(result, metrics) {
    const budget = budgetOf(t.performance, 'http_req_duration{phase:recovery}', 'p(95)');
    const timeline = timelineOf(metrics, 'http_req_duration', WINDOWS, WINDOW);
    const spikeEnd = marks.recovery; // first second of the recovery phase
    const after = timeline.filter((w) => w.t_start_s >= spikeEnd);
    let back = null;
    if (budget !== null) {
      for (let i = 0; i < after.length; i++) {
        if (after.slice(i).every((w) => w.p95 !== null && w.p95 < budget)) {
          back = Math.max(0, after[i].t_start_s - spikeEnd);
          break;
        }
      }
    }
    const during = timeline.filter((w) => w.t_start_s >= marks.spike && w.t_start_s < spikeEnd);
    result.metrics.derived = {
      p95_budget_ms: budget,
      p95_budget_provisional: t.meta.provisional,
      // Resolution = window_seconds. null = never back under the budget inside the recovery window.
      seconds_back_under_p95_budget: back,
      spike_peak_window_p95_ms: during.length ? Math.max.apply(null, during.map((w) => w.p95)) : null,
      phase_starts_s: marks,
      timeline,
    };
    if (budget !== null && back === null) {
      result.notes.push('p95 did not return under the budget within the recovery window: lengthen RECOVERY or look at the queue drain time.');
    }
  },
});
