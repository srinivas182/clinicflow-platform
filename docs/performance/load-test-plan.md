# Load-test plan

Run on **staging built like production** (same server sizes, Redis, MySQL, Reverb, Horizon workers, S3-compatible storage, Octane on) — never on production.

## Targets

| Measure | Target |
|---|---|
| Page and API reads | p95 under **400 ms**, p99 under 1 s |
| Writes (bookings, check-ins via API) | p95 under **800 ms** |
| Errors | under **0.5 %** (excluding deliberate 429 rate limits) |
| Real-time | a queue change reaches open screens within **2 s** |
| Background jobs | message queue wait under 2 min; AI queue under 5 min |

**Sizing note.** 100,000 concurrent users is not 100,000 requests per second. With real-time updates a signed-in user makes about one request every 30 seconds on average (page views plus the 60-second safety refresh), so 100,000 users ≈ **3,300 requests/second**, with bursts (morning check-in) of 2–3×. Size for ~10,000 req/s peak across the app servers behind the load balancer.

## Test data (staging)

- 50 practices, each with 20,000 patients, 2 years of visits, invoices, appointments and lab orders (seed with factories).
- One staff test account per practice; one API key per practice (availability:read, appointments:read/write).

## Scenarios (k6 scripts in tests/load/)

| Script | What it does |
|---|---|
| `public.js` | Practice websites, booking-widget slots, status page (anonymous traffic) |
| `api.js` | Public API: availability and appointment reads, bookings (writes) with API keys |
| `staff.js` | Signed-in staff: queue screens with partial reloads (as the 60 s safety refresh does), patient search, care chart |

Steps: 100 → 500 → 1,000 → 2,500 virtual users, 10 minutes each, then a 30-minute soak at the highest passing level.

## Watch during the run

Horizon (queue wait, failures), MySQL slow-query log (anything over 200 ms), Redis memory, Reverb connections, CPU and memory per app server, error logs.

## Pass and report

Record for each step: requests/s, p50/p95/p99, error rate, slowest endpoints, slow queries found. A step passes when all targets hold; fix the slowest endpoint or query found and repeat.
