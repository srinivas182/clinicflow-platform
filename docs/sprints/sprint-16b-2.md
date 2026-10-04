# Sprint 16B-2 — Support console and status page

| Story | Acceptance | Status |
|---|---|---|
| Support tickets | Practice raises and replies; support replies and closes | Done |
| Consented support access | Practice-granted 1–72 h; read-only; every page logged; ends on expiry or revoke | Done |
| Status page | Components auto-checked with manual override; incidents and maintenance; public page and JSON | Done |

## At deployment
- Host a static copy of the status page outside the main servers, fed from `/status.json`, with an external uptime monitor.

## Review fixes (before merge)
- Only the practice owner can grant support access (anyone who manages settings may end it).
- Static analysis: grant lookup typed safely.

## Known follow-up
- "Online payments" on the status page is reported operational without a live gateway check; a real check is added with deployment.
- The public status page should also be served from separate hosting at deployment so it stays up during an outage (JSON summary available).
