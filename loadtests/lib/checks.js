// Task 10: common checks and status accounting.
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';

export const serverErrors = new Counter('lt_http_5xx');
export const rateLimited = new Counter('lt_http_429');
// Share of responses whose status was not in the list the scenario expected for that request.
export const unexpectedRate = new Rate('lt_unexpected_response');

export function json(res) {
  try {
    return res.json();
  } catch (e) {
    return null;
  }
}

// Errors are RFC 9457 problem+json; `type` is "<APP_URL>/problems/<slug>". Returns the slug or null.
export function problemSlug(res) {
  const body = json(res);
  if (!body || typeof body.type !== 'string') return null;
  return body.type.split('/').pop();
}

// Counts 5xx / 429 and feeds the unexpected-response rate. Returns true when the status was expected.
export function track(res, expected, tags) {
  if (res.status >= 500 || res.status === 0) serverErrors.add(1, tags);
  if (res.status === 429) rateLimited.add(1, tags);
  const ok = expected.indexOf(res.status) !== -1;
  unexpectedRate.add(!ok, tags);
  return ok;
}

export function expectStatus(res, expected, label, tags) {
  const list = Array.isArray(expected) ? expected : [expected];
  const ok = track(res, list, tags);
  check(res, { [label || `status in ${list.join('/')}`]: () => ok }, tags);
  return ok;
}

export function checkRequestId(res, tags) {
  return check(res, { 'has X-Request-Id': (r) => !!r.headers['X-Request-Id'] }, tags);
}

export function checkProblem(res, status, slug, tags) {
  return check(
    res,
    { [`problem ${status} ${slug}`]: (r) => r.status === status && problemSlug(r) === slug },
    tags,
  );
}

export function checkList(res, tags) {
  return check(
    res,
    {
      'list is 200': (r) => r.status === 200,
      'list has data[]': (r) => {
        const body = json(r);
        return !!body && Array.isArray(body.data);
      },
    },
    tags,
  );
}
