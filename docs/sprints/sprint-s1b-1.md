# Sprint S1b-1 — Background messaging and the queue dashboard

| Story | Acceptance | Status |
|---|---|---|
| Background messages | Queued log → job on "messages" queue → sent/failed; 3 attempts with backoff | Done |
| Sync fallback | Sync queue sends immediately (tests, single server) | Done |
| Horizon | Platform domain only, super admin only; supervisors for messages, ai, default/exports | Done |
| Alerts | Hourly email to platform admins on failed jobs | Done |

Verified locally before CI: style, PHPStan level 8, background messaging tests (3), messaging tests (11).

## Next (S1b-2)
AI scribe and lab explanations in the background with on-screen progress; heavy exports and PDFs queued.
