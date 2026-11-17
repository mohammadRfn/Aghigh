// Task 10: thresholds come from loadtests/config/thresholds.json, never from the scenario file.
// Each scenario entry (and optional variant override) has:
//   status      "provisional" until a baseline run exists, then "baseline"
//   baseline    { date, commit } of the run the numbers were calibrated on (null while provisional)
//   thresholds  performance budgets (p95, error rate). Provisional ones are RECORDED but do not fail a make target.
//   assertions  correctness conditions (e.g. no replay mismatch). Always enforced, never provisional.
const FILE = JSON.parse(open(import.meta.resolve('../config/thresholds.json')));

function variantEntry(entry, variant) {
  const variants = entry.variants || {};
  if (variants[variant]) return variants[variant];
  // "fanout-r2" -> "fanout", "reconnect-jitter-r1" -> "reconnect-jitter" -> "reconnect"
  let name = variant.replace(/-r\d+$/, '');
  while (name) {
    if (variants[name]) return variants[name];
    const cut = name.lastIndexOf('-');
    if (cut === -1) break;
    name = name.slice(0, cut);
  }
  return {};
}

export function loadThresholds(scenario, variant) {
  const entry = FILE.scenarios[scenario];
  if (!entry) throw new Error(`loadtests/config/thresholds.json has no entry for scenario "${scenario}"`);
  const override = variantEntry(entry, variant);
  const status = override.status || entry.status || 'provisional';
  if (status !== 'provisional' && status !== 'baseline') {
    throw new Error(`thresholds status for ${scenario}/${variant} must be provisional or baseline, got "${status}"`);
  }
  const baseline = override.baseline || entry.baseline || {};
  if (status === 'baseline' && (!baseline.date || !baseline.commit)) {
    throw new Error(`thresholds for ${scenario}/${variant} are marked baseline but have no baseline.date/commit`);
  }
  const performance = Object.assign({}, entry.thresholds || {}, override.thresholds || {});
  // A variant that defines `assertions` REPLACES the scenario list (the naive stock variant asserts nothing here:
  // its violations are asserted by after-run.sh --expect-violations).
  const assertions = override.assertions !== undefined ? override.assertions : entry.assertions || {};
  return {
    performance,
    assertions,
    meta: {
      status,
      provisional: status !== 'baseline',
      baseline_date: baseline.date || null,
      baseline_commit: baseline.commit || null,
    },
  };
}

// The numeric budget of one expression, e.g. budgetOf(performance, 'http_req_duration{phase:recovery}', 'p(95)') -> 500.
export function budgetOf(performance, metric, aggregate) {
  for (const expression of performance[metric] || []) {
    const match = /^(\w+(?:\([\d.]+\))?)\s*(?:<=|<)\s*([\d.]+)$/.exec(String(expression).replace(/\s+/g, ''));
    if (match && match[1] === aggregate) return parseFloat(match[2]);
  }
  return null;
}

// k6 only reports a tagged sub-metric (e.g. http_reqs{phase:main}) when a threshold names it. These always-true
// expressions exist ONLY to materialize sub-metrics for the result file; lib/summary.js drops them from `thresholds`.
export const MATERIALIZE = { trend: 'max>=0', counter: 'count>=0', rate: 'rate>=0' };

export function materialize(kind, names) {
  const out = {};
  for (const name of names) out[name] = [MATERIALIZE[kind]];
  return out;
}

// Merges threshold maps (performance, assertions, materialized) without losing expressions on the same metric.
export function mergeThresholds() {
  const out = {};
  for (const map of arguments) {
    for (const metric of Object.keys(map || {})) {
      out[metric] = (out[metric] || []).concat(map[metric]);
    }
  }
  return out;
}

// The standard http sub-metrics of one phase, so the result file can report p50..p99, rps and error rate for it.
export function httpPhase(phase) {
  return mergeThresholds(
    materialize('trend', [`http_req_duration{phase:${phase}}`]),
    materialize('counter', [`http_reqs{phase:${phase}}`]),
    materialize('rate', [`http_req_failed{phase:${phase}}`]),
  );
}
