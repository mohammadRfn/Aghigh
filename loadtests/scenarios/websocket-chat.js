// Task 10, scenario 5: WebSocket chat through Traefik (Reverb, Pusher protocol 7). MODE selects one of three runs;
// LT_VARIANT is "<mode>[-<policy>]-r<REVERB_SCALE>" (set by run-scenario.sh), e.g. fanout-r2, reconnect-jitter-r1.
//
//   MODE=ceiling    Ramp IDLE connections (VUS x CONNS_PER_VU, one more per VU every STEP_MS) until handshakes start
//                   to fail. derived.ceiling_approx_connections = the ramp position of the first failure. It is
//                   APPROXIMATE: VUs do not share state, k6 and Traefik have their own fd limits, and with
//                   REVERB_SCALE=2 the split between replicas is whatever Docker DNS gave (sampler.sh records the
//                   per-replica socket and fd counts during the hold). No failure = ceiling not reached: raise CONNS.
//   MODE=fanout     CONVERSATIONS customers each open a conversation and FANOUT sockets on its private channel, then
//                   post MESSAGES messages by REST. The sender embeds its send time in client_message_id
//                   (lt_<ms>_<random>); every receiving socket computes delivery latency against it IN THE SAME k6
//                   PROCESS, so there is no clock skew. Latency = REST request + queue + broadcast + socket.
//                   With REVERB_SCALE=2 the sockets land on both replicas (cross-replica through Redis pub/sub).
//   MODE=reconnect  Reconnect storm. VUS x SOCKETS_PER_VU sockets are dropped at STORM_AT (STORM_TRIGGER=server:
//                   run-scenario.sh restarts Reverb; =client: the script closes them) and come back with
//                   RECONNECT_POLICY=jitter (exponential back-off, full jitter) or =immediate. Compared numbers:
//                   derived.peak_attempts_per_second, lt_ws_recover_ms, lt_ws_reconnect_failed.
// After the run: chat:verify-invariants --rebuild (after-run.sh).
import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { setTimeout, clearTimeout } from 'k6/timers';
import { BASE, VARIANT, baseOptions, envInt, envStr, envSeconds, randomId } from '../lib/config.js';
import { json, track } from '../lib/checks.js';
import { loginPool, bearerHeaders } from '../lib/auth.js';
import { APP_KEY, openSocket, closeSocket, subscribe, sleepMs } from '../lib/pusher.js';
import { loadThresholds, mergeThresholds, httpPhase, materialize } from '../lib/thresholds.js';
import { makeHandleSummary, timelineOf } from '../lib/summary.js';

const MODE = envStr('MODE', 'fanout');
if (['ceiling', 'fanout', 'reconnect'].indexOf(MODE) === -1) throw new Error(`websocket-chat: unknown MODE "${MODE}" (ceiling | fanout | reconnect)`);
const POLICY = envStr('RECONNECT_POLICY', 'jitter');
if (['jitter', 'immediate'].indexOf(POLICY) === -1) throw new Error(`websocket-chat: unknown RECONNECT_POLICY "${POLICY}" (jitter | immediate)`);
const variant = VARIANT === 'default' ? (MODE === 'reconnect' ? `reconnect-${POLICY}` : MODE) : VARIANT;
const HANDSHAKE_TIMEOUT = envInt('HANDSHAKE_TIMEOUT_MS', 10000);

// ceiling
const C_VUS = envInt('VUS', 20);
const C_PER_VU = envInt('CONNS_PER_VU', 100);
const C_STEP = envInt('STEP_MS', 200);
const C_HOLD = envSeconds('HOLD', '30s');
const C_STOP_AFTER = envInt('STOP_AFTER_FAILURES', 3);
// fanout
const F_CONVERSATIONS = envInt('CONVERSATIONS', 10);
const F_SOCKETS = envInt('FANOUT_SOCKETS', 4); // <= CHAT_CONNECTIONS_PER_USER (x RATE_LIMIT_MULTIPLIER)
const F_MESSAGES = envInt('MESSAGES', 30);
const F_INTERVAL = envInt('MSG_INTERVAL_MS', 1000);
const F_SETTLE = envInt('SETTLE_MS', 5000);
// reconnect
const R_VUS = envInt('VUS', 10);
const R_PER_VU = envInt('SOCKETS_PER_VU', 50);
const R_STORM_AT = envSeconds('STORM_AT', '20s');
const R_OBSERVE = envSeconds('OBSERVE', '60s');
const R_TRIGGER = envStr('STORM_TRIGGER', 'server');
const R_BASE = envInt('RECONNECT_BASE_MS', 1000);
const R_CAP = envInt('RECONNECT_CAP_MS', 30000);
const R_MAX_ATTEMPTS = envInt('RECONNECT_MAX_ATTEMPTS', 500);
const R_TOTAL = R_STORM_AT + R_OBSERVE;

const wsOpened = new Counter('lt_ws_connect_ok');
const wsFailed = new Counter('lt_ws_connect_failed');
const wsDropped = new Counter('lt_ws_dropped'); // established sockets closed by the other side
const wsHandshake = new Trend('lt_ws_handshake_ms', true);
const ceilingAt = new Trend('lt_ws_ceiling_position'); // ramp position (connections) of each failed handshake
const deliveryMs = new Trend('lt_ws_delivery_ms', true);
const deliveryMissed = new Rate('lt_ws_delivery_missed'); // expected (message, socket) deliveries that never arrived
const messagesSent = new Counter('lt_ws_messages_sent');
const deliveries = new Counter('lt_ws_deliveries');
const reconnectAttempts = new Counter('lt_ws_reconnect_attempts');
const reconnectFailed = new Counter('lt_ws_reconnect_failed');
const recoverMs = new Trend('lt_ws_recover_ms', true); // drop -> established again
const unrecovered = new Rate('lt_ws_unrecovered'); // sockets still down at the end of the observation window

const t = loadThresholds('websocket-chat', variant);
const attemptWindows = [];
for (let i = 0; i <= Math.ceil(R_TOTAL); i++) attemptWindows.push(`lt_ws_reconnect_attempts{win:${i}}`);

const scenarios = {
  ceiling: { executor: 'per-vu-iterations', vus: C_VUS, iterations: 1, exec: 'ceiling', maxDuration: `${Math.ceil((C_PER_VU * (C_STEP + HANDSHAKE_TIMEOUT)) / 1000) + C_HOLD + 60}s` },
  fanout: { executor: 'per-vu-iterations', vus: F_CONVERSATIONS, iterations: 1, exec: 'fanout', maxDuration: `${Math.ceil((F_MESSAGES * (F_INTERVAL + 2000) + F_SETTLE) / 1000) + 120}s` },
  reconnect: { executor: 'per-vu-iterations', vus: R_VUS, iterations: 1, exec: 'reconnect', maxDuration: `${Math.ceil(R_TOTAL) + 120}s` },
};

export const options = baseOptions({
  scenarios: { [MODE]: scenarios[MODE] },
  thresholds: mergeThresholds(
    t.performance, t.assertions,
    MODE === 'fanout' ? httpPhase('main') : {},
    MODE === 'reconnect' ? materialize('counter', attemptWindows) : {},
    materialize('counter', ['lt_ws_connect_ok', 'lt_ws_connect_failed', 'lt_ws_dropped']),
  ),
});

export function setup() {
  if (!APP_KEY) throw new Error('websocket-chat: REVERB_APP_KEY is empty (make reverb-keys, then run through `make k6-websocket-chat`).');
  const users = MODE === 'fanout' ? loginPool(F_CONVERSATIONS, { start: 1 + envInt('USER_OFFSET', 0) }) : [];
  return { started_at: new Date().toISOString(), users };
}

// ---------------------------------------------------------------- ceiling
export async function ceiling() {
  const sockets = [];
  let failures = 0;
  for (let k = 0; k < C_PER_VU && failures < C_STOP_AFTER; k++) {
    const started = Date.now();
    const sock = await openSocket({ onClose: () => wsDropped.add(1) }, HANDSHAKE_TIMEOUT);
    if (sock.socketId) {
      wsOpened.add(1);
      wsHandshake.add(Date.now() - started);
      sockets.push(sock);
    } else {
      wsFailed.add(1);
      // Every VU is at step k, so about k * VUS sockets were open across the test when this one failed.
      ceilingAt.add(k * C_VUS + exec.vu.idInTest);
      failures++;
      closeSocket(sock);
    }
    await sleepMs(C_STEP);
  }
  await sleepMs(C_HOLD * 1000); // sampler.sh reads the per-replica socket and fd counts during the hold
  sockets.forEach(closeSocket);
}

// ---------------------------------------------------------------- fanout
export async function fanout(data) {
  const tags = { phase: 'main' };
  const user = data.users[(exec.vu.idInTest - 1) % data.users.length];
  const headers = bearerHeaders(user.token);
  const opened = http.post(`${BASE}/api/v1/conversations`, null, { headers, tags: { phase: 'main', name: 'chat.open' } });
  track(opened, [200, 201], tags);
  const conversation = opened.status === 200 || opened.status === 201 ? (json(opened) || {}).data : null;
  if (!check(conversation, { 'conversation opened (201/200)': (c) => !!c && !!c.id }, tags)) return;

  const channel = `private-conversation.${conversation.id}`;
  const pending = {}; // client_message_id -> true (messages this VU really persisted)
  const seen = {};
  let delivered = 0;
  const sockets = [];
  for (let i = 0; i < F_SOCKETS; i++) {
    const index = i;
    const started = Date.now();
    const sock = await openSocket({
      onClose: () => wsDropped.add(1),
      onEvent: (msg) => {
        if (msg.event !== 'message.sent' || msg.channel !== channel || !msg.payload) return;
        const id = msg.payload.client_message_id;
        const stamp = /^lt_(\d+)_/.exec(String(id)); // the send time embedded by the sender (same process, same clock)
        if (!stamp || seen[`${index}:${id}`]) return;
        seen[`${index}:${id}`] = true;
        delivered++;
        deliveries.add(1);
        deliveryMs.add(Date.now() - Number(stamp[1]));
      },
    }, HANDSHAKE_TIMEOUT);
    if (!sock.socketId) {
      wsFailed.add(1);
      closeSocket(sock);
      continue;
    }
    wsOpened.add(1);
    wsHandshake.add(Date.now() - started);
    const status = await subscribe(sock, user.token, channel, 'main');
    check(status, { 'subscribed to own conversation channel': (s) => s === 200 }, tags);
    if (status === 200) sockets.push(sock);
    else closeSocket(sock);
  }

  let persisted = 0;
  for (let n = 0; n < F_MESSAGES && sockets.length > 0; n++) {
    const id = `lt_${Date.now()}_${randomId(8)}`;
    const res = http.post(
      `${BASE}/api/v1/conversations/${conversation.id}/messages`,
      JSON.stringify({ body: `k6 load message ${n + 1}/${F_MESSAGES}`, client_message_id: id }),
      { headers, tags: { phase: 'main', name: 'chat.send' } },
    );
    track(res, [201], tags);
    if (check(res, { 'message persisted (201)': (r) => r.status === 201 }, tags)) {
      pending[id] = true;
      persisted++;
      messagesSent.add(1);
    }
    await sleepMs(F_INTERVAL);
  }
  await sleepMs(F_SETTLE);

  const expected = persisted * sockets.length;
  for (let i = 0; i < expected; i++) deliveryMissed.add(i >= delivered);
  http.post(`${BASE}/api/v1/conversations/${conversation.id}/close`, null, { headers, tags: { phase: 'teardown', name: 'chat.close' } });
  sockets.forEach(closeSocket);
}

// ---------------------------------------------------------------- reconnect
export async function reconnect() {
  let stopping = false;
  const conns = [];
  const win = () => String(Math.max(0, Math.min(Math.ceil(R_TOTAL), Math.floor((Date.now() - exec.scenario.startTime) / 1000))));

  const schedule = (conn) => {
    if (stopping || conn.attempt >= R_MAX_ATTEMPTS) return;
    // jitter: full jitter over an exponentially growing window, capped. immediate: retry at once.
    const delay = POLICY === 'immediate' ? 0 : Math.random() * Math.min(R_CAP, R_BASE * Math.pow(2, conn.attempt));
    conn.attempt++;
    conn.timer = setTimeout(() => {
      conn.timer = null;
      connect(conn);
    }, delay);
  };
  const dropped = (conn) => {
    if (stopping) return;
    wsDropped.add(1);
    conn.sock = null;
    if (conn.droppedAt === null) conn.droppedAt = Date.now();
    schedule(conn);
  };
  const connect = async (conn) => {
    if (stopping) return;
    const started = Date.now();
    if (conn.droppedAt !== null) reconnectAttempts.add(1, { win: win() });
    const sock = await openSocket({ onClose: () => dropped(conn) }, HANDSHAKE_TIMEOUT);
    if (stopping) {
      closeSocket(sock);
      return;
    }
    if (sock.socketId) {
      wsOpened.add(1);
      wsHandshake.add(Date.now() - started);
      conn.sock = sock;
      conn.attempt = 0;
      if (conn.droppedAt !== null) {
        recoverMs.add(Date.now() - conn.droppedAt);
        conn.droppedAt = null;
      }
    } else {
      wsFailed.add(1);
      if (conn.droppedAt !== null) reconnectFailed.add(1);
      closeSocket(sock);
      schedule(conn);
    }
  };

  for (let i = 0; i < R_PER_VU; i++) {
    const conn = { sock: null, attempt: 0, timer: null, droppedAt: null };
    conns.push(conn);
    await connect(conn);
  }

  const untilStorm = R_STORM_AT * 1000 - (Date.now() - exec.scenario.startTime);
  if (untilStorm > 0) await sleepMs(untilStorm);
  if (R_TRIGGER === 'client') {
    for (const conn of conns) {
      if (conn.sock) {
        closeSocket(conn.sock);
        dropped(conn);
      }
    }
  } // server: run-scenario.sh restarts the Reverb containers at STORM_AT; the sockets see the close themselves

  const left = R_TOTAL * 1000 - (Date.now() - exec.scenario.startTime);
  if (left > 0) await sleepMs(left);
  stopping = true;
  for (const conn of conns) {
    unrecovered.add(conn.sock === null);
    if (conn.timer !== null) clearTimeout(conn.timer);
    closeSocket(conn.sock);
  }
}

const params = {
  ceiling: { mode: MODE, vus: C_VUS, conns_per_vu: C_PER_VU, target_connections: C_VUS * C_PER_VU, step_ms: C_STEP, hold_seconds: C_HOLD, handshake_timeout_ms: HANDSHAKE_TIMEOUT },
  fanout: { mode: MODE, conversations: F_CONVERSATIONS, sockets_per_conversation: F_SOCKETS, messages_per_conversation: F_MESSAGES, message_interval_ms: F_INTERVAL, settle_ms: F_SETTLE },
  reconnect: { mode: MODE, policy: POLICY, trigger: R_TRIGGER, sockets: R_VUS * R_PER_VU, storm_at_seconds: R_STORM_AT, observe_seconds: R_OBSERVE, backoff_base_ms: R_BASE, backoff_cap_ms: R_CAP },
};

export const handleSummary = makeHandleSummary({
  scenario: 'websocket-chat',
  variant,
  mainPhase: MODE === 'fanout' ? 'main' : null,
  thresholdMeta: t.meta,
  assertions: t.assertions,
  phases: MODE === 'fanout' ? { main: null } : {},
  params: params[MODE],
  derive(result, metrics) {
    const c = result.metrics.counters;
    const tr = result.metrics.trends;
    if (MODE === 'ceiling') {
      const position = tr.lt_ws_ceiling_position;
      const reached = !!position && position.count >= C_STOP_AFTER; // fewer failures than STOP_AFTER_FAILURES is noise, not a ceiling
      result.metrics.derived = {
        connections_established: c.lt_ws_connect_ok,
        handshake_failures: c.lt_ws_connect_failed,
        ceiling_reached: reached,
        ceiling_approx_connections: reached ? position.min : null, // APPROXIMATE, see the header of this file
        ceiling_is_approximate: true,
        first_failure_position: position && position.count > 0 ? position.min : null,
      };
      result.notes.push(reached
        ? 'ceiling_approx_connections is approximate (ramp position of the first failed handshake, all Reverb replicas together). Per-replica sockets and fds: invariants.resources.'
        : `Fewer than ${C_STOP_AFTER} handshakes failed up to ${C_VUS * C_PER_VU} connections: the ceiling was NOT reached. Raise VUS or CONNS_PER_VU.`);
    } else if (MODE === 'fanout') {
      result.metrics.derived = {
        messages_sent: c.lt_ws_messages_sent,
        deliveries: c.lt_ws_deliveries,
        delivery_p50_ms: tr.lt_ws_delivery_ms ? tr.lt_ws_delivery_ms.p50 : null,
        delivery_p95_ms: tr.lt_ws_delivery_ms ? tr.lt_ws_delivery_ms.p95 : null,
        delivery_p99_ms: tr.lt_ws_delivery_ms ? tr.lt_ws_delivery_ms.p99 : null,
        delivery_missed_rate: result.metrics.rates.lt_ws_delivery_missed ? result.metrics.rates.lt_ws_delivery_missed.rate : null,
      };
      result.notes.push('Delivery latency = receive time minus the send time embedded in client_message_id, both read from the same k6 process clock. Broadcasts are best effort; missed deliveries are gap-filled by clients over REST.');
    } else {
      const timeline = timelineOf(metrics, 'lt_ws_reconnect_attempts', Math.ceil(R_TOTAL) + 1, 1);
      result.metrics.derived = {
        policy: POLICY,
        reconnect_attempts: c.lt_ws_reconnect_attempts === undefined ? 0 : c.lt_ws_reconnect_attempts,
        reconnect_failed_attempts: c.lt_ws_reconnect_failed === undefined ? 0 : c.lt_ws_reconnect_failed,
        peak_attempts_per_second: timeline.length ? Math.max.apply(null, timeline.map((w) => w.count)) : null,
        recover_p50_ms: tr.lt_ws_recover_ms ? tr.lt_ws_recover_ms.p50 : null,
        recover_p95_ms: tr.lt_ws_recover_ms ? tr.lt_ws_recover_ms.p95 : null,
        recover_max_ms: tr.lt_ws_recover_ms ? tr.lt_ws_recover_ms.max : null,
        unrecovered_rate: result.metrics.rates.lt_ws_unrecovered ? result.metrics.rates.lt_ws_unrecovered.rate : null,
        attempts_timeline: timeline,
      };
      result.notes.push('Compare reconnect-jitter with reconnect-immediate only between runs made back to back with the same SOCKETS and trigger.');
    }
  },
});
