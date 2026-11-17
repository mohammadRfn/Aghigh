// Task 10: the request shapes shared by catalog, spike, soak and metrics-overhead.
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { BASE, JSON_HEADERS, envStr, randomId } from './config.js';
import { json, problemSlug, track, checkList } from './checks.js';
import { bearerHeaders } from './auth.js';

export const OPTIMIZED_PATH = '/api/v1/products';
export const NAIVE_PATH = '/api/v1/_bench/naive/products';

export const ordersCreated = new Counter('lt_orders_created');
export const ordersReplayed = new Counter('lt_orders_replayed');
export const ordersOutOfStock = new Counter('lt_orders_out_of_stock');
export const ordersInProgress = new Counter('lt_orders_in_progress');
export const ordersOther = new Counter('lt_orders_other');

function flattenCategories(nodes, out) {
  for (const node of nodes || []) {
    if (node.children && node.children.length) flattenCategories(node.children, out);
    else out.push(node.id);
  }
  return out;
}

// Builds the query list ONCE in setup() from what the seeded catalog really contains (leaf categories, real price
// range), so nothing about the dataset is assumed. The list is deterministic for a given dataset, and iteration i
// uses query i % length: the naive and the optimized runs therefore send exactly the same parameters in the same order.
export function buildCatalogQueries(perPage) {
  const size = perPage || 20;
  const setupParams = { headers: JSON_HEADERS, tags: { phase: 'setup', name: 'catalog.setup' } };
  const queries = [`per_page=${size}`, `per_page=${size}&sort=price_asc`, `per_page=${size}&sort=price_desc`];

  const cats = http.get(`${BASE}/api/v1/categories`, setupParams);
  const leaves = flattenCategories((json(cats) || {}).data, []).sort();
  const wanted = Math.min(4, leaves.length);
  for (let i = 0; i < wanted; i++) {
    const id = leaves[Math.floor((i * leaves.length) / wanted)];
    queries.push(`per_page=${size}&category=${id}`);
    queries.push(`per_page=${size}&category=${id}&sort=price_asc`);
  }

  const cheapest = json(http.get(`${BASE}${OPTIMIZED_PATH}?per_page=1&sort=price_asc`, setupParams));
  const dearest = json(http.get(`${BASE}${OPTIMIZED_PATH}?per_page=1&sort=price_desc`, setupParams));
  const low = cheapest && cheapest.data && cheapest.data[0] ? cheapest.data[0].price_minor : null;
  const high = dearest && dearest.data && dearest.data[0] ? dearest.data[0].price_minor : null;
  if (typeof low === 'number' && typeof high === 'number' && high > low) {
    const step = Math.floor((high - low) / 4);
    queries.push(`per_page=${size}&min_price=${low}&max_price=${low + step}`);
    queries.push(`per_page=${size}&min_price=${low + step}&max_price=${low + 2 * step}&sort=price_asc`);
    queries.push(`per_page=${size}&min_price=${low + 2 * step}&sort=price_desc`);
  }

  // Trigram search terms depend on the seeded names, so they are opt-in: CATALOG_SEARCH_TERMS="term1,term2" (>= 3 chars).
  for (const term of envStr('CATALOG_SEARCH_TERMS', '').split(',').map((t) => t.trim()).filter((t) => t.length >= 3)) {
    queries.push(`per_page=${size}&q=${encodeURIComponent(term)}`);
  }
  return { queries, leaf_categories: leaves.length, price_min_minor: low, price_max_minor: high };
}

export function catalogRead(path, query, phase, name) {
  const tags = { phase, name: name || 'catalog.list' };
  const res = http.get(`${BASE}${path}?${query}`, { headers: { Accept: 'application/json' }, tags });
  track(res, [200], { phase });
  checkList(res, { phase });
  return res;
}

export function orderBody(productId, quantity) {
  return JSON.stringify({ items: [{ product_id: productId, quantity: quantity || 1 }] });
}

export function orderRequest(token, productId, key, phase) {
  return {
    method: 'POST',
    url: `${BASE}/api/v1/orders`,
    body: orderBody(productId, 1),
    params: { headers: bearerHeaders(token, { 'Idempotency-Key': key }), tags: { phase, name: 'orders.store' } },
  };
}

// created | replayed | out_of_stock | in_progress | rate_limited | other
export function classifyOrder(res) {
  if (res.status === 200 || res.status === 201) return res.headers['Idempotency-Replayed'] ? 'replayed' : 'created';
  if (res.status === 409) {
    const slug = problemSlug(res);
    if (slug === 'out-of-stock') return 'out_of_stock';
    if (slug === 'idempotency-in-progress') return 'in_progress';
    return 'other';
  }
  if (res.status === 429) return 'rate_limited';
  return 'other';
}

// The order id of a 2xx answer: the Location header is set for originals and replays alike.
export function orderIdOf(res) {
  const location = res.headers.Location;
  if (location) return location.split('/').pop();
  const body = json(res);
  if (body && body.data && body.data.id) return body.data.id;
  return body && body.id ? body.id : null;
}

export function countOrder(kind, tags) {
  if (kind === 'created') ordersCreated.add(1, tags);
  else if (kind === 'replayed') ordersReplayed.add(1, tags);
  else if (kind === 'out_of_stock') ordersOutOfStock.add(1, tags);
  else if (kind === 'in_progress') ordersInProgress.add(1, tags);
  else if (kind !== 'rate_limited') ordersOther.add(1, tags);
}

// One order for the mixed workloads (spike, soak, metrics-overhead): 201 and 409 out-of-stock are both valid outcomes.
export function placeOrder(session, productId, phase) {
  const spec = orderRequest(session.bearer(), productId, randomId(24), phase);
  const res = http.post(spec.url, spec.body, spec.params);
  const kind = classifyOrder(res);
  countOrder(kind, { phase });
  track(res, [200, 201, 409], { phase });
  check(res, { 'order accepted or out of stock': () => kind === 'created' || kind === 'out_of_stock' }, { phase });
  return res;
}

// iteration -> one request. Shares: orders `orderPct`%, GET /me 10%, own orders 5%, the rest catalog reads.
export function mixedIteration(data, session, phase, iteration, orderPct) {
  const roll = iteration % 100;
  if (roll < orderPct && data.products.length > 0) {
    return placeOrder(session, data.products[iteration % data.products.length], phase);
  }
  if (roll < orderPct + 10) {
    const res = http.get(`${BASE}/api/v1/me`, { headers: session.headers(), tags: { phase, name: 'identity.me' } });
    track(res, [200], { phase });
    check(res, { 'me is 200': (r) => r.status === 200 }, { phase });
    return res;
  }
  if (roll < orderPct + 15) {
    const res = http.get(`${BASE}/api/v1/orders`, { headers: session.headers(), tags: { phase, name: 'orders.index' } });
    track(res, [200], { phase });
    check(res, { 'own orders are 200': (r) => r.status === 200 }, { phase });
    return res;
  }
  return catalogRead(OPTIMIZED_PATH, data.queries[iteration % data.queries.length], phase);
}

export function productIdsFromEnv() {
  return envStr('LT_PRODUCT_IDS', '').split(',').map((id) => id.trim()).filter(Boolean);
}
