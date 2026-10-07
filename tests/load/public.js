// k6 run -e BASE=https://sunrise.staging.clinicflow.co.za tests/load/public.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    stages: [{ duration: '2m', target: 100 }, { duration: '10m', target: 500 }, { duration: '10m', target: 1000 }, { duration: '2m', target: 0 }],
    thresholds: { http_req_failed: ['rate<0.005'], http_req_duration: ['p(95)<400', 'p(99)<1000'] },
};

export default function () {
    const base = __ENV.BASE;
    check(http.get(`${base}/`), { 'website 200': (r) => r.status === 200 });
    check(http.get(`${base}/widget/slots`), { 'slots ok': (r) => r.status === 200 || r.status === 429 });
    sleep(Math.random() * 3 + 2);
}
