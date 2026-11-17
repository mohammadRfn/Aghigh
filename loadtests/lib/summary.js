// Task 10: ONE result schema for every scenario (loadtests/results/SCHEMA.md, rendered by Task 12's bench-report.php).
// handleSummary writes /results/<scenario>-<variant>-<UTC timestamp>.json; after-run.sh later fills `host`,
// `invariants` and appends to `notes`. Nothing here invents a value: what k6 did not measure is null.
import { RESULTS_DIR, SCENARIO, VARIANT, BASE } from './config.js';
import { MATERIALIZE } from './thresholds.js';

export const SCHEMA_VERSION = 1;

const MATERIALIZED = [MATERIALIZE.trend, MATERIALIZE.counter, MATERIALIZE.rate];

function num(value) {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function round(value, digits) {
  if (value === null) return null;
  const factor = Math.pow(10, digits === undefined ? 3 : digits);
  return Math.round(value * factor) / factor;
}

function utcStamp(date) {
  return date.toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z'); // 20250131T142501Z
}

export function trendStats(metric) {
  if (!metric || !metric.values) return null;
  const v = metric.values;
  return {
    p50: round(num(v['p(50)'] !== undefined ? v['p(50)'] : v.med)),
    p90: round(num(v['p(90)'])),
    p95: round(num(v['p(95)'])),
    p99: round(num(v['p(99)'])),
    avg: round(num(v.avg)),
    min: round(num(v.min)),
    max: round(num(v.max)),
    count: num(v.count),
  };
}

// p50..p99 (ms), request count, rps and error rate of one phase. `seconds` = the configured length of that phase;
// without it rps falls back to k6's own rate (count / whole test duration) and says so in rps_basis.
export function httpPhaseStats(metrics, phase, seconds) {
  const suffix = phase ? `{phase:${phase}}` : '';
  const duration = metrics[`http_req_duration${suffix}`];
  if (!duration) return null;
  const reqs = metrics[`http_reqs${suffix}`];
  const failed = metrics[`http_req_failed${suffix}`];
  const count = reqs ? num(reqs.values.count) : null;
  const stats = trendStats(duration);
  const out = {
    scope: phase ? `phase:${phase}` : 'all',
    p50: stats.p50,
    p90: stats.p90,
    p95: stats.p95,
    p99: stats.p99,
    avg: stats.avg,
    min: stats.min,
    max: stats.max,
    requests: count !== null ? count : stats.count,
    rps: null,
    rps_basis: null,
    error_rate: failed ? round(num(failed.values.rate), 6) : null,
  };
  if (out.requests !== null && seconds) {
    out.rps = round(out.requests / seconds, 2);
    out.rps_basis = `requests / configured phase length (${seconds}s)`;
  } else if (reqs) {
    out.rps = round(num(reqs.values.rate), 2);
    out.rps_basis = 'k6 rate over the whole test (setup and warm-up included)';
  }
  return out;
}

function flattenChecks(group, path, out) {
  for (const c of group.checks || []) {
    const total = c.passes + c.fails;
    out.push({ name: c.name, group: path, passes: c.passes, fails: c.fails, rate: total > 0 ? round(c.passes / total, 6) : null });
  }
  for (const g of group.groups || []) flattenChecks(g, path ? `${path}/${g.name}` : g.name, out);
  return out;
}

function observed(metric, expression) {
  const match = /^\s*(\w+(?:\([\d.]+\))?)/.exec(expression);
  if (!match || !metric.values) return null;
  const key = match[1];
  if (metric.values[key] !== undefined) return round(num(metric.values[key]), 6);
  if (key === 'value' && metric.values.value !== undefined) return num(metric.values.value);
  return null;
}

function collectThresholds(metrics, ctx) {
  const assertions = ctx.assertions || {};
  const meta = ctx.thresholdMeta || { provisional: true, baseline_date: null, baseline_commit: null, status: 'provisional' };
  const out = [];
  for (const name of Object.keys(metrics).sort()) {
    const metric = metrics[name];
    for (const expression of Object.keys(metric.thresholds || {})) {
      if (MATERIALIZED.indexOf(expression) !== -1) continue;
      const isAssertion = (assertions[name] || []).indexOf(expression) !== -1;
      out.push({
        metric: name,
        expression,
        kind: isAssertion ? 'assertion' : 'performance',
        value: observed(metric, expression),
        passed: metric.thresholds[expression].ok === true,
        provisional: isAssertion ? false : meta.provisional,
        status: isAssertion ? 'assertion' : meta.status,
        baseline_date: isAssertion ? null : meta.baseline_date,
        baseline_commit: isAssertion ? null : meta.baseline_commit,
      });
    }
  }
  return out;
}

// Every custom metric is prefixed lt_ ; tagged sub-metrics keep their {tag:value} suffix.
function collectCustom(metrics) {
  const out = { counters: {}, rates: {}, trends: {}, gauges: {} };
  for (const name of Object.keys(metrics).sort()) {
    if (name.indexOf('lt_') !== 0) continue;
    const metric = metrics[name];
    const v = metric.values || {};
    if (metric.type === 'counter') out.counters[name] = num(v.count);
    else if (metric.type === 'rate') out.rates[name] = { rate: round(num(v.rate), 6), passes: num(v.passes), fails: num(v.fails) };
    else if (metric.type === 'trend') out.trends[name] = trendStats(metric);
    else if (metric.type === 'gauge') out.gauges[name] = { value: num(v.value), min: num(v.min), max: num(v.max) };
  }
  return out;
}

function envBlock() {
  const e = (name) => (__ENV[name] === undefined || __ENV[name] === '' ? null : __ENV[name]);
  return {
    APP_ENV: e('LT_ENV_APP_ENV'),
    APP_DEBUG: e('LT_ENV_APP_DEBUG'),
    LOG_LEVEL: e('LT_ENV_LOG_LEVEL'),
    STOCK_STRATEGY: e('LT_ENV_STOCK_STRATEGY'),
    STOCK_NAIVE_DELAY_MS: e('LT_ENV_STOCK_NAIVE_DELAY_MS'),
    METRICS_ENABLED: e('LT_ENV_METRICS_ENABLED'),
    RATE_LIMIT_MULTIPLIER: e('LT_ENV_RATE_LIMIT_MULTIPLIER'), // null = unset (limits at their configured values)
    REVERB_SCALE: e('LT_ENV_REVERB_SCALE'),
    SENTRY_TRACES_SAMPLE_RATE: e('LT_ENV_SENTRY_TRACES_SAMPLE_RATE'),
    SENTRY_DSN_SET: e('LT_ENV_SENTRY_DSN_SET'),
    observability_stack: e('LT_ENV_OBSERVABILITY') || 'unknown', // up | down | unknown
  };
}

export function buildResult(data, ctx) {
  const metrics = data.metrics || {};
  const finished = new Date();
  const durationMs = data.state && typeof data.state.testRunDurationMs === 'number' ? data.state.testRunDurationMs : null;
  const setupData = data.setup_data || {};
  const startedAt = setupData.started_at || (durationMs !== null ? new Date(finished.getTime() - durationMs).toISOString() : null);
  const runId = __ENV.LT_RUN_ID || utcStamp(startedAt ? new Date(startedAt) : finished);
  const mainPhase = ctx.mainPhase === undefined ? 'main' : ctx.mainPhase;

  const phases = {};
  for (const phase of Object.keys(ctx.phases || {})) {
    const stats = httpPhaseStats(metrics, phase, ctx.phases[phase]);
    if (stats) phases[phase] = stats;
  }
  const http = (mainPhase && phases[mainPhase]) || httpPhaseStats(metrics, null, null);
  const custom = collectCustom(metrics);
  const iterations = metrics.iterations ? metrics.iterations.values : null;
  const dropped = metrics.dropped_iterations ? num(metrics.dropped_iterations.values.count) : 0;

  const result = {
    schema_version: SCHEMA_VERSION,
    scenario: ctx.scenario || SCENARIO,
    variant: ctx.variant || VARIANT,
    run: {
      id: runId,
      git_sha: __ENV.LT_GIT_SHA || null,
      git_dirty: __ENV.LT_GIT_DIRTY === undefined || __ENV.LT_GIT_DIRTY === '' ? null : __ENV.LT_GIT_DIRTY === 'true',
      started_at: startedAt,
      finished_at: finished.toISOString(),
      duration_seconds: durationMs === null ? null : round(durationMs / 1000, 1),
      k6_version: __ENV.LT_K6_VERSION || null,
      base_url: BASE,
      params: Object.assign({}, ctx.params || {}, setupData.params || {}),
      env: envBlock(),
    },
    host: null, // filled by loadtests/scripts/after-run.sh (host-info.sh)
    metrics: {
      p50: http ? http.p50 : null,
      p90: http ? http.p90 : null,
      p95: http ? http.p95 : null,
      p99: http ? http.p99 : null,
      rps: http ? http.rps : null,
      error_rate: http ? http.error_rate : null,
      unit: 'ms',
      http,
      phases,
      iterations: {
        count: iterations ? num(iterations.count) : null,
        rate: iterations ? round(num(iterations.rate), 2) : null,
        dropped,
      },
      counters: custom.counters,
      rates: custom.rates,
      trends: custom.trends,
      gauges: custom.gauges,
      derived: {},
    },
    thresholds: collectThresholds(metrics, ctx),
    checks: flattenChecks(data.root_group || {}, '', []),
    invariants: null, // filled by loadtests/scripts/after-run.sh
    notes: (ctx.notes || []).slice(),
  };

  if (dropped > 0) {
    result.notes.push(`k6 dropped ${dropped} iterations: the load generator (or maxVUs) could not keep the arrival rate, so the applied load was lower than configured.`);
  }
  if (result.thresholds.some((t) => t.kind === 'performance' && t.provisional)) {
    result.notes.push('Performance thresholds are PROVISIONAL (no baseline yet): a miss is recorded here but does not fail the make target.');
  }
  if (typeof ctx.derive === 'function') ctx.derive(result, metrics, setupData);
  return result;
}

function fmt(value, unit) {
  return value === null || value === undefined ? 'n/a' : `${value}${unit || ''}`;
}

export function renderText(result, file) {
  const m = result.metrics;
  const lines = [];
  lines.push('');
  lines.push(`== ${result.scenario} / ${result.variant}  (run ${result.run.id}, ${fmt(result.run.duration_seconds, 's')})`);
  lines.push(`   http ${m.http ? m.http.scope : 'n/a'}: p50=${fmt(m.p50, 'ms')} p90=${fmt(m.p90, 'ms')} p95=${fmt(m.p95, 'ms')} p99=${fmt(m.p99, 'ms')} rps=${fmt(m.rps)} error_rate=${fmt(m.error_rate)}`);
  lines.push(`   iterations: ${fmt(m.iterations.count)} (dropped ${m.iterations.dropped})`);
  const counters = Object.keys(m.counters).filter((name) => name.indexOf('{') === -1);
  if (counters.length) lines.push(`   counters: ${counters.map((name) => `${name.replace(/^lt_/, '')}=${m.counters[name]}`).join(' ')}`);
  for (const key of Object.keys(m.derived)) {
    const value = m.derived[key];
    if (value === null || typeof value !== 'object') lines.push(`   ${key}: ${fmt(value)}`);
  }
  for (const t of result.thresholds) {
    const label = t.kind === 'assertion' ? 'assertion' : t.provisional ? 'provisional' : 'baseline';
    lines.push(`   [${t.passed ? 'ok' : 'MISS'}] (${label}) ${t.metric} ${t.expression}  observed=${fmt(t.value)}`);
  }
  const failedChecks = result.checks.filter((c) => c.fails > 0);
  lines.push(`   checks: ${result.checks.length - failedChecks.length}/${result.checks.length} clean`);
  for (const c of failedChecks) lines.push(`   [FAIL] check "${c.name}": ${c.fails} of ${c.passes + c.fails} failed`);
  for (const note of result.notes) lines.push(`   note: ${note}`);
  lines.push(`   result file: ${file}`);
  lines.push('');
  return lines.join('\n');
}

// ctx: { scenario?, variant?, params, phases: {name: seconds}, mainPhase, thresholdMeta, assertions, notes, derive(result, metrics, setupData) }
export function makeHandleSummary(ctx) {
  return function handleSummary(data) {
    const result = buildResult(data, ctx);
    const file = `${RESULTS_DIR}/${result.scenario}-${result.variant}-${result.run.id}.json`;
    const out = {};
    out[file] = JSON.stringify(result, null, 2);
    out.stdout = renderText(result, file);
    return out;
  };
}

// Per-window p95 of a metric tagged `win:<index>` (see lib/timeline.js). Windows without samples are skipped.
export function timelineOf(metrics, metricName, windows, windowSeconds, extraTag) {
  const out = [];
  for (let i = 0; i < windows; i++) {
    const metric = metrics[`${metricName}{${extraTag ? `${extraTag},` : ''}win:${i}}`];
    if (!metric || !metric.values) continue;
    const v = metric.values;
    if (metric.type === 'counter') {
      if (num(v.count) === null || v.count === 0) continue;
      out.push({ window: i, t_start_s: i * windowSeconds, count: v.count });
    } else {
      if (!v.count) continue;
      out.push({ window: i, t_start_s: i * windowSeconds, p95: round(num(v['p(95)'])), max: round(num(v.max)), count: v.count });
    }
  }
  return out;
}
