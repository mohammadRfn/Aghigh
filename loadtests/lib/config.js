// Task 10: shared configuration for every scenario in loadtests/scenarios.
// Everything goes through Traefik (BASE_URL), so edge overhead is part of every number.
// Parameters come from the environment (`make k6-<scenario> RATE=80`); the defaults below are LOAD SHAPES, not results.

export const BASE = (__ENV.BASE_URL || 'http://traefik').replace(/\/+$/, '');
export const WS_BASE = BASE.replace(/^http/, 'ws');
export const SCENARIO = __ENV.LT_SCENARIO || 'adhoc';
export const VARIANT = __ENV.LT_VARIANT || 'default';
export const RESULTS_DIR = (__ENV.RESULTS_DIR || '/results').replace(/\/+$/, '');
// Must be one of CORS_ALLOWED_ORIGINS: POST /auth/refresh requires it (RequireOrigin) and Reverb checks the host.
export const ORIGIN = __ENV.ALLOWED_ORIGIN || 'http://localhost:5173';
// Known password written by `php artisan loadtest:seed-users` (non-production only).
export const PASSWORD = __ENV.LOADTEST_USER_PASSWORD || 'Loadtest-Passphrase-123!';

export function envStr(name, fallback) {
  const raw = __ENV[name];
  return raw === undefined || raw === '' ? fallback : raw;
}

export function envInt(name, fallback) {
  const raw = __ENV[name];
  if (raw === undefined || raw === '') return fallback;
  const value = parseInt(raw, 10);
  if (Number.isNaN(value)) throw new Error(`${name} must be an integer, got "${raw}"`);
  return value;
}

export function envFloat(name, fallback) {
  const raw = __ENV[name];
  if (raw === undefined || raw === '') return fallback;
  const value = parseFloat(raw);
  if (Number.isNaN(value)) throw new Error(`${name} must be a number, got "${raw}"`);
  return value;
}

// "90", "90s", "5m", "2h" -> seconds.
export function seconds(value) {
  const match = /^(\d+(?:\.\d+)?)(ms|s|m|h)?$/.exec(String(value).trim());
  if (!match) throw new Error(`cannot parse duration "${value}" (use 30s, 5m, 2h)`);
  const n = parseFloat(match[1]);
  const unit = match[2] || 's';
  return unit === 'ms' ? n / 1000 : unit === 's' ? n : unit === 'm' ? n * 60 : n * 3600;
}

export function envSeconds(name, fallback) {
  return seconds(envStr(name, fallback));
}

// Accounts created by loadtest:seed-users. Customers are 1..count, agents 1..agents.
export function userEmail(n) {
  return `loadtest-${String(n).padStart(5, '0')}@example.test`;
}

export function agentEmail(n) {
  return `loadtest-agent-${String(n).padStart(3, '0')}@example.test`;
}

export const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };

// Request params with the tags every scenario uses:
//   phase  warmup | main | setup | auth | ... (thresholds only look at their own phase)
//   name   a stable route label, so URLs with ids or cursors never become metric series
export function params(phase, name, headers, extra) {
  return Object.assign({ headers: Object.assign({}, JSON_HEADERS, headers || {}), tags: { phase, name } }, extra || {});
}

// Options shared by every scenario. `lt_scenario` and `variant` are test-wide tags on every sample
// (`scenario` itself is a k6 system tag = the executor name, so it is not reused).
export function baseOptions(extra) {
  return Object.assign(
    {
      tags: { lt_scenario: SCENARIO, variant: VARIANT },
      summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(50)', 'p(90)', 'p(95)', 'p(99)', 'count'],
      setupTimeout: envStr('SETUP_TIMEOUT', '10m'),
      teardownTimeout: '2m',
      noCookiesReset: true, // each Session owns its jar; the refresh cookie must survive iterations
      userAgent: `aghigh-k6/${SCENARIO}`,
    },
    extra || {},
  );
}

export function randomId(length) {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
  let out = '';
  for (let i = 0; i < (length || 24); i++) out += chars[Math.floor(Math.random() * chars.length)];
  return out;
}
