# Sprint 8 — Patient portal, legacy import, compliance centre and public website

| Story | Acceptance | Status |
|---|---|---|
| Portal sign-in | Cell + SMS code; no account enumeration; throttled | Done |
| Family profiles | Guardian manages children's profiles; cannot open other patients | Done |
| Portal content | Today's ticket and queue, bookings, released results only, invoices with payment, scripts | Done |
| Legacy import | CSV; validated per row; duplicates and bad rows reported; consent required at next check-in | Done |
| Audit centre | Search, filter, CSV export; exports are themselves audited | Done |
| POPIA export | Everything held about a patient plus their access log, as a download | Done |
| CMS and directory | Sanitised pages; published home page; verified providers only | Done |

## Deferred
- Patient accounts across providers and the mobile app — Network Hub (Sprint 10) and the Flutter apps.
- Provider mini websites and booking widget — Sprint 16.
- Large imports on the queue (now synchronous; fine for a few thousand rows).
