// Task 10: authentication helpers.
//   - setup() logs every user in ONCE (loginPool) and hands { token, expiresAt, refresh } to the VUs.
//   - Each VU wraps its users in a Session with its OWN cookie jar holding the HttpOnly refresh cookie
//     (aghigh_refresh, path /api/v1/auth) and refreshes the access token before it expires (JWT_ACCESS_TTL = 10 min
//     by default, so anything longer than that, e.g. soak, depends on this).
//   - A refresh rotates the cookie. A user must therefore belong to exactly ONE VU (sessionPicker slices the pool):
//     two VUs refreshing the same family would trip refresh-reuse detection and revoke it. If a refresh still fails
//     the Session logs in again and counts it (lt_auth_relogins), so the run keeps going and the result shows it.
import http from 'k6/http';
import exec from 'k6/execution';
import { Counter } from 'k6/metrics';
import { BASE, ORIGIN, PASSWORD, JSON_HEADERS, envInt, userEmail } from './config.js';

export const REFRESH_COOKIE = 'aghigh_refresh';
const AUTH_PATH = '/api/v1/auth';
const SKEW_MS = envInt('TOKEN_REFRESH_SKEW_SECONDS', 60) * 1000;

export const authRefreshes = new Counter('lt_auth_refreshes');
export const authRelogins = new Counter('lt_auth_relogins');
export const authFailures = new Counter('lt_auth_failures');

function parseTokens(res) {
  if (res.status !== 200) return null;
  let body = null;
  try {
    body = res.json();
  } catch (e) {
    return null;
  }
  if (!body || !body.access_token) return null;
  const cookie = res.cookies && res.cookies[REFRESH_COOKIE];
  return {
    token: body.access_token,
    expiresAt: Date.now() + (body.expires_in || 600) * 1000,
    refresh: cookie && cookie.length ? cookie[0].value : null,
  };
}

function loginSpec(email, password, phase) {
  return {
    method: 'POST',
    url: `${BASE}${AUTH_PATH}/login`,
    body: JSON.stringify({ email, password }),
    params: { headers: JSON_HEADERS, tags: { phase, name: 'auth.login' } },
  };
}

// One login. Returns { email, password, token, expiresAt, refresh } or null; `res` is attached for checks.
export function login(email, password, phase) {
  const spec = loginSpec(email, password || PASSWORD, phase || 'setup');
  const res = http.post(spec.url, spec.body, spec.params);
  const tokens = parseTokens(res);
  return { ok: tokens !== null, status: res.status, res, seed: tokens ? Object.assign({ email, password: password || PASSWORD }, tokens) : null };
}

// For setup(): logs in users start..start+count-1 in parallel batches. Throws when an account is missing so a run
// never starts half-authenticated (run `make loadtest-reset` first).
export function loginPool(count, opts) {
  const o = Object.assign({ start: 1, batch: 10, phase: 'setup', emailOf: userEmail, password: PASSWORD }, opts || {});
  const pool = [];
  for (let offset = 0; offset < count; offset += o.batch) {
    const emails = [];
    for (let i = offset; i < Math.min(count, offset + o.batch); i++) emails.push(o.emailOf(o.start + i));
    const responses = http.batch(emails.map((email) => loginSpec(email, o.password, o.phase)));
    for (let i = 0; i < emails.length; i++) {
      const tokens = parseTokens(responses[i]);
      if (!tokens) {
        throw new Error(
          `login failed for ${emails[i]} (HTTP ${responses[i].status}). Seed the accounts first: make loadtest-reset ` +
            '(a 429 here means RATE_LIMIT_MULTIPLIER is not applied; a 401 means the password or the seed is wrong).',
        );
      }
      pool.push(Object.assign({ email: emails[i], password: o.password }, tokens));
    }
  }
  return pool;
}

export class Session {
  constructor(seed) {
    this.email = seed.email;
    this.password = seed.password || PASSWORD;
    this.token = seed.token || null;
    this.expiresAt = seed.expiresAt || 0;
    this.jar = new http.CookieJar(); // per-VU, per-user jar: only this Session ever rotates this refresh family
    this.hasRefresh = false;
    if (seed.refresh) this.storeRefresh(seed.refresh);
  }

  storeRefresh(value) {
    // The cookie is not Secure outside production, so it travels over plain http to Traefik.
    this.jar.set(`${BASE}${AUTH_PATH}`, REFRESH_COOKIE, value, { path: AUTH_PATH });
    this.hasRefresh = true;
  }

  adopt(res) {
    const tokens = parseTokens(res);
    if (!tokens) return false;
    this.token = tokens.token;
    this.expiresAt = tokens.expiresAt;
    if (tokens.refresh) this.storeRefresh(tokens.refresh);
    return true;
  }

  // Refresh through the cookie jar; fall back to a fresh login. Returns false when both fail.
  renew() {
    if (this.hasRefresh) {
      const res = http.post(`${BASE}${AUTH_PATH}/refresh`, null, {
        jar: this.jar,
        headers: { Accept: 'application/json', Origin: ORIGIN },
        tags: { phase: 'auth', name: 'auth.refresh' },
      });
      if (this.adopt(res)) {
        authRefreshes.add(1);
        return true;
      }
    }
    const spec = loginSpec(this.email, this.password, 'auth');
    const res = http.post(spec.url, spec.body, Object.assign({ jar: this.jar }, spec.params));
    if (this.adopt(res)) {
      authRelogins.add(1);
      return true;
    }
    authFailures.add(1);
    return false;
  }

  needsRenewal() {
    return !this.token || Date.now() >= this.expiresAt - SKEW_MS;
  }

  bearer() {
    if (this.needsRenewal()) this.renew();
    return this.token;
  }

  headers(extra) {
    return Object.assign({}, JSON_HEADERS, { Authorization: `Bearer ${this.bearer()}` }, extra || {});
  }
}

// VU-local state: each VU owns the slice of the pool where index % slots == its slot, and rotates inside it.
// So orders spread over many users (per-user limiters) while no two VUs ever share a refresh family.
let owned = null;
let cursor = 0;

export function sessionPicker(pool, slots) {
  if (owned === null) {
    const slot = (exec.vu.idInTest - 1) % slots;
    owned = [];
    for (let i = slot; i < pool.length; i += slots) owned.push(new Session(pool[i]));
    if (owned.length === 0) throw new Error(`VU ${exec.vu.idInTest} owns no user: the pool (${pool.length}) is smaller than the VU slots (${slots})`);
  }
  const session = owned[cursor % owned.length];
  cursor++;
  return session;
}

export function bearerHeaders(token, extra) {
  return Object.assign({}, JSON_HEADERS, { Authorization: `Bearer ${token}` }, extra || {});
}
