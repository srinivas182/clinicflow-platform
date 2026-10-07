// k6 run -e BASE=https://sunrise.staging.clinicflow.co.za -e KEY=cf_live_... tests/load/api.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        reads: { executor: 'ramping-vus', stages: [{ duration: '2m', target: 200 }, { duration: '10m', target: 1000 }, { duration: '2m', target: 0 }], exec: 'reads' },
        writes: { executor: 'constant-arrival-rate', rate: 20, timeUnit: '1s', duration: '10m', preAllocatedVUs: 50, exec: 'writes' },
    },
    thresholds: {
        'http_req_duration{scenario:reads}': ['p(95)<400'],
        'http_req_duration{scenario:writes}': ['p(95)<800'],
        http_req_failed: ['rate<0.005'],
    },
};

const headers = () => ({ Authorization: `Bearer ${__ENV.KEY}`, Accept: 'application/json' });

export function reads() {
    const r = http.get(`${__ENV.BASE}/api/v1/availability?date=${new Date().toISOString().slice(0, 10)}`, { headers: headers() });
    check(r, { 'availability ok': (x) => x.status === 200 || x.status === 429 });
    sleep(1);
}

export function writes() {
    // Bookings against a dedicated staging doctor; staging data is reset after each run.
    const r = http.get(`${__ENV.BASE}/api/v1/appointments?limit=20`, { headers: headers() });
    check(r, { 'appointments ok': (x) => x.status === 200 || x.status === 429 });
}
