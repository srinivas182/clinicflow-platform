# Sprint 16B-2 — Support console and status page

| Story | Acceptance | Status |
|---|---|---|
| Support tickets | Practice raises and replies; support replies and closes | Done |
| Consented support access | Practice-granted 1–72 h; read-only; every page logged; ends on expiry or revoke | Done |
| Status page | Components auto-checked with manual override; incidents and maintenance; public page and JSON | Done |

## At deployment
- Host a static copy of the status page outside the main servers, fed from `/status.json`, with an external uptime monitor.
