# Sprint 17C-2 — API write endpoints and signed webhooks

| Story | Acceptance | Status |
|---|---|---|
| Book / reschedule / cancel | Same rules as booking screen; reschedule atomic | Done |
| Register patient | Integrator states consent, method and time; audited | Done |
| Webhook endpoints | Public HTTPS only (SSRF-safe); events; secret shown once; on/off; test | Done |
| Deliveries | Signed; IDs and status only; retries ~20 h; log; switch-off after 5 failures + email | Done |
