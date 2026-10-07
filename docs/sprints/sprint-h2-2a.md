# Sprint H2-2a — Real-time for patients and the waiting-room display

| Story | Acceptance | Status |
|---|---|---|
| Portal sign-in | Own patient/chat/call channels only; signature verified | Done |
| Display sign-in | Queue channel only; valid device token | Done |
| Portal home | Visit status live on the patient's own channel | Done |
| Chat | Live for patients too | Done |
| Calls | call.changed for scribe consent/progress and extensions; 30 s safety net | Done |
| Display | Live queue (ticket numbers only) | Done |

Verified locally before CI: style, PHPStan level 8, TypeScript, build, real-time tests (6).

## Next (H2-2b)
Caching and index tuning; load-test plan (k6 scripts and targets).
