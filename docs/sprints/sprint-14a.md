# Sprint 14A — Branches, groups, custom domains, calendars

| Story | Acceptance | Status |
|---|---|---|
| Branches | Package limit + extra-branch add-on; tagging; branch switcher; per-branch queue and stock; transfers | Done |
| Groups | Group admins; totals only; combined invoice settles members | Done |
| Custom domains | DNS records shown; TXT verification; served on own domain; TLS allow-list endpoint | Done |
| Calendars | Google / Microsoft sync without names; cancellations removed; busy blocking; iCal feed | Done |

## At deployment
- Wildcard certificate for *.clinicflow.co.za (DNS provider API access needed).
- On-demand certificates for verified custom domains: web server asks `/internal/tls/allowed?domain=…`.
- Register Clinic Flow with Google Cloud and Microsoft Entra using the redirect URLs shown in Admin → Calendars.
