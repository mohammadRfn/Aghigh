// Task 10, scenario 1: catalog. GET /_bench/naive/products (N+1, OFFSET, index-defeating expressions) against the
// optimized GET /products, SAME query set and page depth, `ramping-arrival-rate`, run back to back by `make k6-catalog`.
// Variants (LT_VARIANT): naive | optimized-cold | optimized-warm.
//   optimized-cold  the cache Redis db is flushed right before the run (run-scenario.sh) and the warm-up stage hits
//                   /catalog/ping instead of the catalog, so PHP is warm but the catalog cache is not.
//   optimized-warm  runs right after the cold one, without a flush.
// The warm-up stage (the ramp) is tagged phase=warmup and excluded from every threshold.
// Limits to know: the optimized endpoint sits behind `public-catalog` (CATALOG_PUBLIC_RATE per minute per IP, times
// RATE_LIMIT_MULTIPLIER); k6 is ONE IP. Keep RATE below that or raise the multiplier, otherwise 429s (counted in
// lt_http_429) distort the comparison. The naive endpoint has no limiter.
import http from 'k6/http';
import exec from 'k6/execution';
import { BASE, VARIANT, baseOptions, envInt, envSeconds } from '../lib/config.js';
import { json, track } from '../lib/checks.js';
import { loadThresholds, mergeThresholds, httpPhase } from '../lib/thresholds.js';
import { makeHandleSummary } from '../lib/summary.js';
import { buildCatalogQueries, catalogRead, NAIVE_PATH, OPTIMIZED_PATH } from '../lib/workload.js';

const variant = VARIANT === 'default' ? 'optimized-warm' : VARIANT;
if (['naive', 'optimized-cold', 'optimized-warm'].indexOf(variant) === -1) {
  throw new Error(`catalog: unknown variant "${variant}" (naive | optimized-cold | optimized-warm)`);
}
const PATH = variant === 'naive' ? NAIVE_PATH : OPTIMIZED_PATH;
const RATE = envInt('RATE', 40); // iterations (= requests) per second at full load
const WARMUP = envSeconds('WARMUP', '20s');
const DURATION = envSeconds('DURATION', '60s');
const PER_PAGE = envInt('PER_PAGE', 20);
const DEPTH = envInt('PAGE_DEPTH', 50); // pages followed per query through meta.next_cursor before starting over
const VUS = envInt('PREALLOCATED_VUS', 50);
const MAX_VUS = envInt('MAX_VUS', 200);

const t = loadThresholds('catalog', variant);

export const options = baseOptions({
  scenarios: {
    catalog: {
      executor: 'ramping-arrival-rate',
      startRate: Math.max(1, Math.round(RATE / 10)),
      timeUnit: '1s',
      preAllocatedVUs: VUS,
      maxVUs: MAX_VUS,
      stages: [
        { target: RATE, duration: `${WARMUP}s` }, // warm-up ramp, excluded from thresholds
        { target: RATE, duration: `${DURATION}s` },
      ],
      gracefulStop: '30s',
    },
  },
  thresholds: mergeThresholds(t.performance, t.assertions, httpPhase('main'), httpPhase('warmup')),
});

export function setup() {
  const built = buildCatalogQueries(PER_PAGE);
  const probe = http.get(`${BASE}${PATH}?per_page=1`, { headers: { Accept: 'application/json' }, tags: { phase: 'setup', name: 'catalog.setup' } });
  if (probe.status !== 200) {
    throw new Error(`catalog: ${PATH} answered ${probe.status}. The naive route exists only with APP_ENV=local|loadtest (make loadtest-up).`);
  }
  return {
    started_at: new Date().toISOString(),
    queries: built.queries,
    params: {
      query_count: built.queries.length,
      leaf_categories: built.leaf_categories,
      price_min_minor: built.price_min_minor,
      price_max_minor: built.price_max_minor,
    },
  };
}

// VU-local cursor walk: query -> { cursor, depth }. Each endpoint follows its OWN cursors (they are implementation-bound).
const walk = {};

export default function (data) {
  const warm = (Date.now() - exec.scenario.startTime) / 1000 < WARMUP;
  const phase = warm ? 'warmup' : 'main';
  if (warm && variant === 'optimized-cold') {
    const res = http.get(`${BASE}/api/v1/catalog/ping`, { headers: { Accept: 'application/json' }, tags: { phase, name: 'catalog.ping' } });
    track(res, [200], { phase });
    return;
  }
  const query = data.queries[exec.scenario.iterationInTest % data.queries.length];
  const state = walk[query] || { cursor: null, depth: 0 };
  const res = catalogRead(PATH, state.cursor ? `${query}&cursor=${encodeURIComponent(state.cursor)}` : query, phase);
  const body = res.status === 200 ? json(res) : null;
  const next = body && body.meta ? body.meta.next_cursor : null;
  if (next && state.depth + 1 < DEPTH) walk[query] = { cursor: next, depth: state.depth + 1 };
  else delete walk[query];
}

export const handleSummary = makeHandleSummary({
  scenario: 'catalog',
  variant,
  thresholdMeta: t.meta,
  assertions: t.assertions,
  phases: { main: DURATION, warmup: WARMUP },
  params: {
    endpoint: PATH,
    rate_per_second: RATE,
    warmup_seconds: WARMUP,
    duration_seconds: DURATION,
    per_page: PER_PAGE,
    page_depth: DEPTH,
    preallocated_vus: VUS,
    max_vus: MAX_VUS,
    catalog_products: __ENV.LT_CATALOG_PRODUCTS ? parseInt(__ENV.LT_CATALOG_PRODUCTS, 10) : null, // rows in `products` at run time
    cache: variant === 'optimized-cold' ? 'cold (cache db flushed before the run)' : variant === 'optimized-warm' ? 'warm' : 'none (no-store)',
  },
  notes: ['Compare naive vs optimized only between runs made back to back on the same machine (loadtests/README.md).'],
});
