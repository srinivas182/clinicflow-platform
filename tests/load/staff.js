// k6 run -e BASE=https://sunrise.staging.clinicflow.co.za -e COOKIE='clinicflow_session=...' tests/load/staff.js
// Use a signed-in staging staff session (copy the cookie from the browser after signing in).
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    stages: [{ duration: '2m', target: 100 }, { duration: '10m', target: 500 }, { duration: '10m', target: 2500 }, { duration: '2m', target: 0 }],
    thresholds: { http_req_failed: ['rate<0.005'], http_req_duration: ['p(95)<400', 'p(99)<1000'] },
};

export default function () {
    const base = __ENV.BASE;
    const params = { headers: { Cookie: __ENV.COOKIE, 'X-Inertia': 'true', 'X-Inertia-Partial-Component': 'FrontDesk/Index', 'X-Inertia-Partial-Data': 'visits,counts' } };
    // Queue screen safety refresh (what each open screen does every 60 s with real-time on).
    check(http.get(`${base}/front-desk`, params), { 'queue ok': (r) => r.status === 200 || r.status === 409 });
    check(http.get(`${base}/patients?q=thandi`, { headers: { Cookie: __ENV.COOKIE } }), { 'search ok': (r) => r.status === 200 || r.status === 429 });
    sleep(30 + Math.random() * 30);
}
