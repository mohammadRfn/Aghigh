// Task 9: order traffic for make obs-verify (the Orders dashboard and the orders queue panels need real orders).
// Through Traefik only. Users and stock are prepared by scripts/obs-orders.sh. Stays far below the limiters
// (orders: 30/min per user, concurrency cap 2): two users, one order every 1.5 s.
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 1,
  iterations: 1,
  thresholds: { checks: ['rate==1'] },
};

const BASE = __ENV.BASE_URL || 'http://traefik';
const PASSWORD = __ENV.SMOKE_PASSWORD || 'Smoke-Password-123!';
const PRODUCTS = (__ENV.PRODUCT_IDS || '').split(',').filter(Boolean);
const ORDERS = parseInt(__ENV.ORDERS || '8', 10);
const SETTLE_SECONDS = parseInt(__ENV.SETTLE_SECONDS || '12', 10); // let the saga (pay, confirm, notify) drain

function api(method, path, token, body, extra) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  Object.assign(headers, extra || {});
  return http.request(method, `${BASE}${path}`, body === undefined ? null : JSON.stringify(body), { headers });
}

function login(email) {
  const res = api('POST', '/api/v1/auth/login', null, { email, password: PASSWORD });
  check(res, { [`login ${email}`]: (r) => r.status === 200 });
  return res.status === 200 ? res.json().access_token : null;
}

function randomKey() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
  let out = '';
  for (let i = 0; i < 24; i++) out += chars[Math.floor(Math.random() * chars.length)];
  return out;
}

export default function () {
  if (PRODUCTS.length === 0) throw new Error('PRODUCT_IDS is empty (run scripts/obs-orders.sh)');
  const tokens = [login('chat-smoke-a@example.test'), login('chat-smoke-b@example.test')];
  let first = null;

  for (let i = 0; i < ORDERS; i++) {
    const token = tokens[i % 2];
    const key = randomKey();
    const body = { items: [{ product_id: PRODUCTS[i % PRODUCTS.length], quantity: 1 }] };
    const res = api('POST', '/api/v1/orders', token, body, { 'Idempotency-Key': key });
    check(res, { 'order placed (200/201)': (r) => r.status === 200 || r.status === 201 });
    if (first === null) first = { token, key, body };
    sleep(1.5);
  }

  const replay = api('POST', '/api/v1/orders', first.token, first.body, { 'Idempotency-Key': first.key });
  check(replay, {
    'same key replays the stored response': (r) => (r.status === 200 || r.status === 201) && !!r.headers['Idempotency-Replayed'],
  });

  const list = api('GET', '/api/v1/orders', tokens[0]);
  check(list, { 'own orders listed (200)': (r) => r.status === 200 });

  sleep(SETTLE_SECONDS);
}
