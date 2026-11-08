// Task 8: real-stack chat smoke (make verify-chat). Kept small on purpose; Task 10 builds load scenarios on it.
// Through Traefik only: REST on BASE_URL, WebSockets on BASE_URL/app/{key} (Pusher protocol 7).
// Asserts: private-channel auth via POST /api/broadcasting/auth, REST post -> `message.sent` with the right seq on
// every subscribed socket within 2 s (several sockets, so with REVERB_SCALE=2 some sit on the other replica),
// disallowed Origin refused (pusher:error 4009), another customer's channel denied (403).
import http from 'k6/http';
import { check } from 'k6';
import { WebSocket } from 'k6/websockets';
import { setTimeout } from 'k6/timers';

export const options = {
  vus: 1,
  iterations: 1,
  thresholds: { checks: ['rate==1'] },
};

const BASE = __ENV.BASE_URL || 'http://traefik';
const WS_BASE = BASE.replace(/^http/, 'ws');
const KEY = __ENV.REVERB_APP_KEY;
const PASSWORD = __ENV.CHAT_SMOKE_PASSWORD || 'Smoke-Password-123!';
const ALLOWED_ORIGIN = __ENV.ALLOWED_ORIGIN || 'http://localhost:5173';
const EVIL_ORIGIN = 'https://evil.example';
const FANOUT = parseInt(__ENV.FANOUT_SOCKETS || '4', 10); // customer sockets on the channel (<= connection cap - 1)
const HOLD = parseInt(__ENV.HOLD_SOCKETS || '12', 10); // idle sockets kept open for the per-replica sample
const HOLD_SECONDS = parseInt(__ENV.HOLD_SECONDS || '15', 10);
const DELIVERY_MS = 2000;

const sleepMs = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function json(res) {
  try {
    return res.json();
  } catch (e) {
    return null;
  }
}

function api(method, path, token, body) {
  const params = { headers: { Accept: 'application/json', 'Content-Type': 'application/json' } };
  if (token) params.headers.Authorization = `Bearer ${token}`;
  return http.request(method, `${BASE}${path}`, body === undefined ? null : JSON.stringify(body), params);
}

function login(email) {
  const res = api('POST', '/api/v1/auth/login', null, { email, password: PASSWORD });
  check(res, { [`login ${email}`]: (r) => r.status === 200 });
  return (json(res) || {}).access_token;
}

function randomId() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
  let out = '';
  for (let i = 0; i < 24; i++) out += chars[Math.floor(Math.random() * chars.length)];
  return out;
}

// Opens a Pusher connection; resolves once connection_established or pusher:error arrives (or the socket closes).
function openSocket(origin) {
  return new Promise((resolve) => {
    const ws = new WebSocket(`${WS_BASE}/app/${KEY}?protocol=7&client=k6&version=8.4.0&flash=false`, null, {
      headers: { Origin: origin },
    });
    const sock = { ws, socketId: null, error: null, inbox: [], waiters: [], closed: false };
    let settled = false;
    const settle = () => {
      if (!settled) {
        settled = true;
        resolve(sock);
      }
    };
    ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      if (typeof msg.data === 'string') {
        try {
          msg.payload = JSON.parse(msg.data);
        } catch (e) {
          msg.payload = msg.data;
        }
      } else {
        msg.payload = msg.data;
      }
      if (msg.event === 'pusher:connection_established') {
        sock.socketId = msg.payload.socket_id;
        settle();
      } else if (msg.event === 'pusher:error') {
        sock.error = msg.payload;
        settle();
      } else if (msg.event === 'pusher:ping') {
        ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      }
      sock.inbox.push(msg);
      sock.waiters = sock.waiters.filter((w) => !w(msg));
    };
    ws.onclose = () => {
      sock.closed = true;
      settle();
    };
    ws.onerror = () => settle();
  });
}

// Resolves with the first message matching `predicate` (already received or future), or null after `ms`.
function waitFor(sock, predicate, ms) {
  return new Promise((resolve) => {
    const seen = sock.inbox.find(predicate);
    if (seen) return resolve(seen);
    let done = false;
    sock.waiters.push((msg) => {
      if (!done && predicate(msg)) {
        done = true;
        resolve(msg);
        return true;
      }
      return false;
    });
    setTimeout(() => {
      if (!done) {
        done = true;
        resolve(null);
      }
    }, ms);
  });
}

function authorize(token, socketId, channel) {
  return api('POST', '/api/broadcasting/auth', token, { socket_id: socketId, channel_name: channel });
}

async function subscribe(sock, token, channel) {
  const res = authorize(token, sock.socketId, channel);
  if (res.status !== 200) return res.status;
  sock.ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: json(res).auth, channel } }));
  const ok = await waitFor(sock, (m) => m.event === 'pusher_internal:subscription_succeeded' && m.channel === channel, 3000);
  return ok ? 200 : 'no-subscription-ack';
}

export default async function () {
  const tokenA = login('chat-smoke-a@example.test');
  const tokenB = login('chat-smoke-b@example.test');
  const tokenAgent = login('chat-smoke-agent@example.test');

  // Idle sockets for the per-replica distribution sample (verify-chat.sh counts ESTABLISHED sockets meanwhile).
  const idle = [];
  for (let i = 0; i < HOLD; i++) idle.push(await openSocket(ALLOWED_ORIGIN));
  check(idle, { 'idle sockets connected': (list) => list.every((s) => s.socketId) });
  const startedAt = Date.now();

  // Customer A opens a conversation; the agent claims until it gets that one, then releases any other it took.
  const opened = api('POST', '/api/v1/conversations', tokenA);
  check(opened, { 'conversation opened (201/200)': (r) => r.status === 201 || r.status === 200 });
  const conversation = json(opened).data;
  const others = [];
  let claimed = conversation.status === 'claimed' ? conversation : null;
  for (let i = 0; i < 20 && !claimed; i++) {
    const res = api('POST', '/api/v1/support/queue/claim', tokenAgent);
    if (res.status !== 200) break;
    const got = json(res).data;
    if (got.id === conversation.id) claimed = got;
    else others.push(got.id);
  }
  others.forEach((id) => api('POST', `/api/v1/conversations/${id}/release`, tokenAgent));
  check(claimed, { 'agent claimed the smoke conversation': (c) => c !== null && c.id === conversation.id });

  const channel = `private-conversation.${conversation.id}`;
  const customerSockets = [];
  for (let i = 0; i < FANOUT; i++) customerSockets.push(await openSocket(ALLOWED_ORIGIN));
  const agentSocket = await openSocket(ALLOWED_ORIGIN);
  const listeners = [...customerSockets, agentSocket];
  for (const s of customerSockets) {
    check(await subscribe(s, tokenA, channel), { 'customer subscribed to own channel': (st) => st === 200 });
  }
  check(await subscribe(agentSocket, tokenAgent, channel), { 'assigned agent subscribed': (st) => st === 200 });

  // Agent posts by REST; every subscribed socket must get message.sent with the persisted seq within 2 s.
  const sent = api('POST', `/api/v1/conversations/${conversation.id}/messages`, tokenAgent, {
    body: 'سلام! چطور می‌توانم کمک کنم؟',
    client_message_id: randomId(),
  });
  check(sent, { 'message persisted (201)': (r) => r.status === 201 });
  const seq = json(sent).data.seq;
  const t0 = Date.now();
  const deliveries = await Promise.all(
    listeners.map((s) => waitFor(s, (m) => m.event === 'message.sent' && m.channel === channel && m.payload.seq === seq, DELIVERY_MS)),
  );
  const elapsed = Date.now() - t0;
  check(deliveries, {
    'message.sent reached every socket within 2s': (list) => list.every((m) => m !== null),
    'payload carries seq + client_message_id + server time': (list) =>
      list.every((m) => m && m.payload.client_message_id && m.payload.created_at && m.payload.server_time),
  });
  console.log(`delivery to ${listeners.length} sockets: ${deliveries.filter((m) => m).length} received, ${elapsed} ms`);

  // Customer posts; the agent socket receives it with the next seq.
  const reply = api('POST', `/api/v1/conversations/${conversation.id}/messages`, tokenA, { body: 'ممنون', client_message_id: randomId() });
  const replySeq = json(reply).data.seq;
  const got = await waitFor(agentSocket, (m) => m.event === 'message.sent' && m.payload.seq === replySeq, DELIVERY_MS);
  check(got, { 'agent got the customer reply with seq+1': (m) => m !== null && replySeq === seq + 1 });

  // Disallowed Origin: Reverb answers pusher:error 4009 and closes.
  const evil = await openSocket(EVIL_ORIGIN);
  check(evil, { 'evil origin refused (4009)': (s) => !s.socketId && s.error !== null && Number(s.error.code) === 4009 });

  // Another customer cannot authorize A's channel (same 403 for malformed ids).
  const probe = await openSocket(ALLOWED_ORIGIN);
  check(authorize(tokenB, probe.socketId, channel), { "other customer's channel denied (403)": (r) => r.status === 403 });
  check(authorize(tokenB, probe.socketId, 'private-conversation.not-a-uuid'), { 'malformed channel id denied (403)': (r) => r.status === 403 });

  // Keep the idle sockets until the sample window is over, then clean up.
  const left = HOLD_SECONDS * 1000 - (Date.now() - startedAt);
  if (left > 0) await sleepMs(left);
  api('POST', `/api/v1/conversations/${conversation.id}/close`, tokenA);
  [...idle, ...listeners, probe, evil].forEach((s) => {
    if (!s.closed) s.ws.close();
  });
}
