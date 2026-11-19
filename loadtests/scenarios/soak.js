// Task 10, scenario 4: soak. Long, moderate, constant mixed load. DURATION defaults to 30m; the documented long run is
// `make k6-soak DURATION=2h`. The access token lives 10 minutes (JWT_ACCESS_TTL), so every VU refreshes through its
// own refresh cookie (lib/auth.js); lt_auth_refreshes / lt_auth_relogins / lt_auth_failures show how that went.
// While k6 runs, loadtests/scripts/sampler.sh samples `docker stats`, Redis INFO memory (both instances), the
// Postgres connection count, PgBouncer SHOW POOLS (cl_waiting) and /internal/metrics into
// loadtests/results/soak-<variant>-<run>.samples.ndjson; after-run.sh reports the slope of each series (leak detection).
import { steadyScenario } from '../lib/steady.js';

const s = steadyScenario({
  scenario: 'soak',
  rate: 5,
  warmup: '60s',
  duration: '30m',
  orderPct: 15,
  users: 200,
  vus: 20,
  maxVus: 100,
  notes: ['Leak detection lives in invariants.resources.containers.<name>.mem_bytes.slope_per_hour (also redis, postgres, reverb series) (filled by after-run.sh from the sampler file), not in the k6 metrics.'],
});

export const options = s.options;
export const setup = s.setup;
export default s.run;
export const handleSummary = s.handleSummary;
