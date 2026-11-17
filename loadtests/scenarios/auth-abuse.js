// Task 10, scenario 6: auth abuse. Runs with RATE_LIMIT_MULTIPLIER UNSET (run-scenario.sh recreates the app without
// it), so the limiters fire at their configured values. One VU, strictly sequential, five phases:
//   debug-ip   GET /_debug/client-ip with and without a spoofed X-Forwarded-For: the resolved IP must not change.
//   stuffing   credential stuffing: many emails (half real, half unknown), a few common passwords, one IP.
//              Expect 401 everywhere, no 200, no 5xx. Each email gets fewer attempts than the limit, so no 429.
//   lockout    one account: `identity.lockout.attempts` wrong passwords (all below the limiter), then the CORRECT
//              password must be refused (401, lockout, not 429), and accepted again after the lock expires.
//   xff        one account, correct password, a different X-Forwarded-For on every request: the first 429 must still
//              arrive at attempt limit+1. If it never does, the header was trusted (lt_auth_xff_trusted).
//   limit      one account, correct password: attempts 1..limit are 200, attempt limit+1 is the first 429 (with
//              Retry-After and RateLimit-Limit). Runs LAST: right after it run-scenario.sh logs the same account in
//              from ANOTHER IP (the host), which must succeed while this IP is still limited.
// The limit, lockout attempts and lockout base seconds are read from the running app by run-scenario.sh
// (LT_AUTH_LOGIN_LIMIT, LT_LOCKOUT_ATTEMPTS, LT_LOCKOUT_BASE_SECONDS); nothing is hard-coded here.
// The accounts used here end up locked or limited: run `make loadtest-reset` before the next scenario.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { BASE, PASSWORD, JSON_HEADERS, baseOptions, envInt, userEmail } from '../lib/config.js';
import { json, problemSlug, track } from '../lib/checks.js';
import { loadThresholds, mergeThresholds, httpPhase, materialize } from '../lib/thresholds.js';
import { makeHandleSummary } from '../lib/summary.js';

const LIMIT = envInt('LT_AUTH_LOGIN_LIMIT', 0);
const LOCK_ATTEMPTS = envInt('LT_LOCKOUT_ATTEMPTS', 0);
const LOCK_BASE = envInt('LT_LOCKOUT_BASE_SECONDS', 0);
if (LIMIT < 1 || LOCK_ATTEMPTS < 1 || LOCK_BASE < 1) {
  throw new Error('auth-abuse: LT_AUTH_LOGIN_LIMIT / LT_LOCKOUT_ATTEMPTS / LT_LOCKOUT_BASE_SECONDS are missing. Run it through `make k6-auth-abuse`.');
}
const USER_BASE = envInt('ABUSE_USER_BASE', 401); // accounts base..base+EMAILS/2-1 are sprayed; base+90..92 play the named roles
const EMAILS = envInt('STUFFING_EMAILS', 60);
const BATCH = envInt('STUFFING_BATCH', 6);
const EXTRA = envInt('ATTEMPTS_BEYOND_LIMIT', 3);
// A refused correct password may count as one more failure and double the lock (base * 2), so wait for that case.
const LOCK_WAIT = envInt('LOCKOUT_WAIT_SECONDS', LOCK_BASE * 2 + 3);
const COMMON_PASSWORDS = ['Password123!', 'Qwerty-2024!!', 'Welcome-12345'];
const LEGIT = userEmail(USER_BASE + 90);
const XFF_USER = userEmail(USER_BASE + 91);
const VICTIM = userEmail(USER_BASE + 92);

const limitMismatch = new Counter('lt_auth_limit_mismatch'); // first 429 was not at attempt limit+1
const xffTrusted = new Counter('lt_auth_xff_trusted'); // a spoofed X-Forwarded-For changed the client IP / the limiter key
const lockoutMismatch = new Counter('lt_auth_lockout_mismatch');
const stuffingSuccess = new Counter('lt_auth_stuffing_success'); // a sprayed password logged in (must stay 0)
const stuffing401 = new Counter('lt_auth_stuffing_401');
const stuffing429 = new Counter('lt_auth_stuffing_429');
const first429 = new Trend('lt_auth_first_429_at'); // attempt number of the first 429 (limit and xff phases)
const everyCounter = [limitMismatch, xffTrusted, lockoutMismatch, stuffingSuccess, stuffing401, stuffing429];

const t = loadThresholds('auth-abuse', 'default');

http.setResponseCallback(http.expectedStatuses(200, 401, 429));

export const options = baseOptions({
  scenarios: { abuse: { executor: 'per-vu-iterations', vus: 1, iterations: 1, maxDuration: '20m' } },
  thresholds: mergeThresholds(
    t.performance, t.assertions,
    httpPhase('stuffing'), httpPhase('limit'), httpPhase('xff'), httpPhase('lockout'),
    materialize('counter', ['lt_auth_stuffing_401', 'lt_auth_stuffing_429']),
  ),
});

function loginSpec(email, password, phase, headers) {
  return {
    method: 'POST',
    url: `${BASE}/api/v1/auth/login`,
    body: JSON.stringify({ email, password }),
    params: { headers: Object.assign({}, JSON_HEADERS, headers || {}), tags: { phase, name: 'auth.login' } },
  };
}

function attempt(email, password, phase, headers) {
  const spec = loginSpec(email, password, phase, headers);
  return http.post(spec.url, spec.body, spec.params);
}

function clientIp(headers) {
  const res = http.get(`${BASE}/api/v1/_debug/client-ip`, { headers: Object.assign({ Accept: 'application/json' }, headers || {}), tags: { phase: 'debug-ip', name: 'debug.client-ip' } });
  const body = res.status === 200 ? json(res) : null;
  return body ? body.ip : null;
}

export function setup() {
  return { started_at: new Date().toISOString(), params: { k6_client_ip: clientIp() } };
}

function phaseDebugIp() {
  const tags = { phase: 'debug-ip' };
  const plain = clientIp();
  const spoofed = clientIp({ 'X-Forwarded-For': '203.0.113.77', 'X-Real-IP': '203.0.113.77', Forwarded: 'for=203.0.113.77' });
  const ok = plain !== null && spoofed === plain;
  if (!ok) xffTrusted.add(1, tags);
  check(null, {
    '_debug/client-ip is reachable (APP_ENV local|loadtest)': () => plain !== null,
    'spoofed X-Forwarded-For does not change the resolved client IP': () => ok,
  }, tags);
}

function phaseStuffing() {
  const tags = { phase: 'stuffing' };
  const emails = [];
  for (let i = 0; i < EMAILS; i++) {
    emails.push(i % 2 === 0 ? userEmail(USER_BASE + i / 2) : `stuffing-unknown-${i}@example.test`);
  }
  for (const password of COMMON_PASSWORDS) {
    for (let offset = 0; offset < emails.length; offset += BATCH) {
      const responses = http.batch(emails.slice(offset, offset + BATCH).map((email) => loginSpec(email, password, 'stuffing')));
      for (const res of responses) {
        track(res, [401], tags);
        if (res.status === 200) stuffingSuccess.add(1, tags);
        else if (res.status === 401) stuffing401.add(1, tags);
        else if (res.status === 429) stuffing429.add(1, tags);
      }
    }
  }
}

function phaseLockout() {
  const tags = { phase: 'lockout' };
  if (LOCK_ATTEMPTS + 2 > LIMIT) {
    check(null, { 'lockout phase skipped: lockout attempts + 2 exceed the login limit, the two cannot be told apart': () => false }, tags);
    lockoutMismatch.add(1, tags);
    return;
  }
  let wrongOk = true;
  for (let i = 0; i < LOCK_ATTEMPTS; i++) {
    const res = attempt(VICTIM, `Wrong-Password-${i}!`, 'lockout');
    track(res, [401], tags);
    if (res.status !== 401) wrongOk = false;
  }
  const locked = attempt(VICTIM, PASSWORD, 'lockout');
  track(locked, [401], tags);
  sleep(LOCK_WAIT);
  const unlocked = attempt(VICTIM, PASSWORD, 'lockout');
  track(unlocked, [200], tags);
  const ok = wrongOk && locked.status === 401 && unlocked.status === 200;
  if (!ok) lockoutMismatch.add(1, tags);
  check(null, {
    'lockout: wrong passwords are 401 (below the limiter)': () => wrongOk,
    'lockout: correct password refused while locked (401, not 429)': () => locked.status === 401,
    'lockout: correct password accepted after the lock expired': () => unlocked.status === 200,
  }, tags);
}

// Sends LIMIT + EXTRA logins with the correct password and returns the attempt number of the first 429 (or null).
function limitedAt(email, phase, headerFor) {
  const tags = { phase };
  let first = null;
  let headersOk = true;
  for (let i = 1; i <= LIMIT + EXTRA; i++) {
    const res = attempt(email, PASSWORD, phase, headerFor ? headerFor(i) : null);
    track(res, [200, 429], tags);
    if (res.status === 429 && first === null) {
      first = i;
      headersOk = !!res.headers['Retry-After'] && String(res.headers['Ratelimit-Limit'] || res.headers['RateLimit-Limit']) === String(LIMIT) && problemSlug(res) === 'too-many-requests';
    }
  }
  if (first !== null) first429.add(first, tags);
  return { first, headersOk };
}

function phaseXff() {
  const tags = { phase: 'xff' };
  const got = limitedAt(XFF_USER, 'xff', (i) => ({ 'X-Forwarded-For': `203.0.113.${i}`, 'X-Real-IP': `203.0.113.${i}`, Forwarded: `for=203.0.113.${i}` }));
  if (got.first === null) xffTrusted.add(1, tags);
  else if (got.first !== LIMIT + 1) limitMismatch.add(1, tags);
  check(null, { 'xff: rotating X-Forwarded-For does not dodge the limiter (first 429 at limit+1)': () => got.first === LIMIT + 1 }, tags);
}

function phaseLimit() {
  const tags = { phase: 'limit' };
  const got = limitedAt(LEGIT, 'limit', null);
  if (got.first !== LIMIT + 1) limitMismatch.add(1, tags);
  check(null, {
    'limit: first 429 arrives exactly at attempt limit+1': () => got.first === LIMIT + 1,
    'limit: 429 is problem too-many-requests with Retry-After and RateLimit-Limit = limit': () => got.first !== null && got.headersOk,
  }, tags);
}

export default function () {
  for (const counter of everyCounter) counter.add(0);
  phaseDebugIp();
  phaseStuffing();
  phaseLockout();
  phaseXff();
  phaseLimit();
}

export const handleSummary = makeHandleSummary({
  scenario: 'auth-abuse',
  variant: 'default',
  mainPhase: 'stuffing',
  thresholdMeta: t.meta,
  assertions: t.assertions,
  phases: { stuffing: null, limit: null, xff: null, lockout: null },
  params: {
    login_limit_per_minute: LIMIT, lockout_attempts: LOCK_ATTEMPTS, lockout_base_seconds: LOCK_BASE, lockout_wait_seconds: LOCK_WAIT,
    stuffing_emails: EMAILS, stuffing_passwords: COMMON_PASSWORDS.length, legit_account: LEGIT, xff_account: XFF_USER, lockout_account: VICTIM,
  },
  notes: ['metrics.p50..p99 describe the stuffing phase. The cross-IP login and the security log counts are under invariants.auth (after-run).'],
  derive(result) {
    const c = result.metrics.counters;
    const firsts = result.metrics.trends.lt_auth_first_429_at;
    result.metrics.derived = {
      configured_limit: LIMIT,
      first_429_at_attempt_min: firsts ? firsts.min : null,
      first_429_at_attempt_max: firsts ? firsts.max : null,
      stuffing_attempts: EMAILS * COMMON_PASSWORDS.length,
      stuffing_401: c.lt_auth_stuffing_401,
      stuffing_429: c.lt_auth_stuffing_429,
      stuffing_logged_in: c.lt_auth_stuffing_success,
      server_errors: c.lt_http_5xx === undefined ? 0 : c.lt_http_5xx,
    };
  },
});
