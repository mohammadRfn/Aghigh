// Task 10: Pusher protocol 7 helpers on top of k6/websockets (same wire behaviour as loadtests/chat-smoke.js).
// WebSockets go through Traefik: BASE_URL/app/{REVERB_APP_KEY}.
import http from 'k6/http';
import { WebSocket } from 'k6/websockets';
import { setTimeout, clearTimeout } from 'k6/timers';
import { BASE, WS_BASE, ORIGIN, envStr } from './config.js';
import { bearerHeaders } from './auth.js';

export const APP_KEY = envStr('REVERB_APP_KEY', '');

export const sleepMs = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function socketUrl() {
  return `${WS_BASE}/app/${APP_KEY}?protocol=7&client=k6&version=8.4.0&flash=false`;
}

// Opens one connection. Resolves with the socket object once `pusher:connection_established` or `pusher:error`
// arrives, the socket closes/errors, or `timeoutMs` passes (socketId stays null on every failure path).
//   handlers.onEvent(msg, sock)  every decoded frame after the handshake (pings are answered here)
//   handlers.onClose(sock)       once, when an ESTABLISHED socket closes
export function openSocket(handlers, timeoutMs, origin) {
  const h = handlers || {};
  return new Promise((resolve) => {
    const sock = { ws: null, socketId: null, error: null, closed: false, openedAt: Date.now(), waiters: [] };
    let settled = false;
    let timer = null;
    const settle = () => {
      if (settled) return;
      settled = true;
      if (timer !== null) clearTimeout(timer);
      resolve(sock);
    };
    try {
      sock.ws = new WebSocket(socketUrl(), null, { headers: { Origin: origin || ORIGIN } });
    } catch (e) {
      sock.error = { message: String(e) };
      sock.closed = true;
      settle();
      return;
    }
    timer = setTimeout(() => {
      timer = null;
      if (!settled) {
        sock.error = { message: `no handshake within ${timeoutMs} ms` };
        try {
          sock.ws.close();
        } catch (e) {
          // already gone
        }
        settle();
      }
    }, timeoutMs || 10000);

    sock.ws.onmessage = (event) => {
      let msg;
      try {
        msg = JSON.parse(event.data);
      } catch (e) {
        return;
      }
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
        return;
      }
      if (msg.event === 'pusher:error') {
        sock.error = msg.payload;
        settle();
        return;
      }
      if (msg.event === 'pusher:ping') {
        sock.ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
        return;
      }
      sock.waiters = sock.waiters.filter((waiter) => !waiter(msg));
      if (h.onEvent) h.onEvent(msg, sock);
    };
    sock.ws.onclose = () => {
      const wasEstablished = sock.socketId !== null && !sock.closed;
      sock.closed = true;
      settle();
      if (wasEstablished && h.onClose) h.onClose(sock);
    };
    sock.ws.onerror = (event) => {
      if (!sock.error) sock.error = { message: (event && event.error) || 'socket error' };
      settle();
    };
  });
}

export function closeSocket(sock) {
  if (!sock || !sock.ws || sock.closed) return;
  sock.closed = true; // a deliberate close never fires onClose handlers
  try {
    sock.ws.close();
  } catch (e) {
    // already gone
  }
}

// Resolves with the first FUTURE frame matching `predicate`, or null after `ms`.
export function waitFor(sock, predicate, ms) {
  return new Promise((resolve) => {
    let done = false;
    const timer = setTimeout(() => {
      if (!done) {
        done = true;
        resolve(null);
      }
    }, ms);
    sock.waiters.push((msg) => {
      if (done) return true;
      if (!predicate(msg)) return false;
      done = true;
      clearTimeout(timer);
      resolve(msg);
      return true;
    });
  });
}

// Private-channel subscription through the single auth route POST /api/broadcasting/auth. Returns 200 on success,
// the HTTP status of a refused auth, or 'no-subscription-ack'.
export async function subscribe(sock, token, channel, phase) {
  const res = http.post(
    `${BASE}/api/broadcasting/auth`,
    JSON.stringify({ socket_id: sock.socketId, channel_name: channel }),
    { headers: bearerHeaders(token), tags: { phase: phase || 'main', name: 'broadcasting.auth' } },
  );
  if (res.status !== 200) return res.status;
  let auth = null;
  try {
    auth = res.json().auth;
  } catch (e) {
    return 'bad-auth-body';
  }
  const ack = waitFor(sock, (m) => m.event === 'pusher_internal:subscription_succeeded' && m.channel === channel, 5000);
  sock.ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth, channel } }));
  return (await ack) ? 200 : 'no-subscription-ack';
}
