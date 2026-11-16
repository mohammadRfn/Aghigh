// Task 10, scenario 7 (deferred from Task 9): what do metrics and tracing cost? The SAME catalog + order load, run
// back to back by `make k6-metrics-overhead` with the app containers recreated per variant:
//   metrics-off  METRICS_ENABLED=false, SENTRY_TRACES_SAMPLE_RATE=0.0
//   metrics-on   METRICS_ENABLED=true,  SENTRY_TRACES_SAMPLE_RATE=0.0
//   traces-on    METRICS_ENABLED=true,  SENTRY_TRACES_SAMPLE_RATE=0.05 (only meaningful with SENTRY_LARAVEL_DSN set;
//                run.env.SENTRY_DSN_SET records whether it was)
//   obs-up       metrics-on with the observability stack running: resource cost and screenshots ONLY.
//                Its latency numbers are never compared with the others (the stack competes for the same CPU).
// after-run.sh records aghigh_metrics_series and the memory of the metrics Redis db (ADR 0010: move it to a separate
// instance if that keyspace grows).
import { VARIANT } from '../lib/config.js';
import { steadyScenario } from '../lib/steady.js';

if (['metrics-off', 'metrics-on', 'traces-on', 'obs-up'].indexOf(VARIANT) === -1) {
  throw new Error(`metrics-overhead: unknown variant "${VARIANT}" (metrics-off | metrics-on | traces-on | obs-up)`);
}

const s = steadyScenario({
  scenario: 'metrics-overhead',
  rate: 20,
  warmup: '20s',
  duration: '3m',
  orderPct: 20,
  users: 200,
  vus: 50,
  maxVus: 200,
  params: { latency_comparable: VARIANT !== 'obs-up' },
  notes: VARIANT === 'obs-up'
    ? ['obs-up: observability stack running. Use this run for resource cost and screenshots only, NEVER for latency numbers.']
    : ['Compare variants only between runs made back to back on the same machine; report the median of 3 runs each.'],
});

export const options = s.options;
export const setup = s.setup;
export default s.run;
export const handleSummary = s.handleSummary;
