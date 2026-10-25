import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: 5,
  duration: '15s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
  },
};

const BASE = __ENV.BASE_URL || 'http://traefik';

export default function () {
  const res = http.get(`${BASE}/api/v1/ordering/ping`);
  check(res, {
    'status is 200': (r) => r.status === 200,
    'has request id': (r) => !!r.headers['X-Request-Id'],
  });
  sleep(0.2);
}
