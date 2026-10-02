# Sprint 2 — Onboarding, packages, rosters and appointments

**Squad B:** packages, subscriptions, provider onboarding and verification, subdomains.
**Squad A:** appointments, rosters, rooms.

| Story | Acceptance | Status |
|---|---|---|
| Provider signs up online | Own database, subdomain, trial, checklist and owner account created in one step; reserved/taken addresses, wrong packages and existing accounts refused | Done |
| Pricing from the package builder | Only active packages shown; admin edits price, trial and active flag | Done |
| Verification and approval | Approval blocked until every required check for the provider type is verified | Done |
| Non-payment | Read-only after trial + 7-day grace; writes refused, reads allowed, nothing deleted | Done |
| Rosters and rooms | No overlapping sessions per person or room | Done |
| Appointments | Free slots from rosters; no double booking; cancellation needs a reason | Done |

## Deferred
- Subscription payment collection (platform payment gateway) — Sprint 3 alongside provider payment gateways.
- Certificate uploads on sign-up — with the documents/storage work.
- Patient search inside the booking screen — Sprint 3 front desk.
