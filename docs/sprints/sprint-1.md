# Sprint 1 — Identity and patient registry

**Squad B (platform):** sign-in with one-time codes, roles and permissions, workspace switcher, audit log.
**Squad A (clinical):** patient registry, registration rules, SA ID checks, consent.

## Delivered

| Story | Acceptance | Status |
|---|---|---|
| Staff sign in with password + one-time code | Wrong password gives one generic message; code expires after 5 minutes and 5 attempts; attempts rate limited | Done |
| One account, many workspaces | Workspace list shows only active, unexpired memberships; locum access expires | Done |
| Open a workspace on the provider's own domain | Single-use, 60-second link; reuse or wrong provider is refused | Done |
| Role templates per provider type | Clinic 11 roles, pharmacy and lab only relevant roles; only prescribers hold `scripts.sign` | Done |
| Audit log | Sign-ins, workspace opens, staff changes and registrations recorded; entries cannot be edited or deleted | Done |
| Register a patient | SA ID check digit, DOB and sex from ID; passport/permit need number and country; consent rules by age; cell or no-cell; POPIA + treatment consent; no duplicate SA ID | Done |
| Search first | By SA ID (keyed hash), cell or name; never across providers | Done |

## Deferred to later sprints

- SMS delivery of codes (log driver now; SMS gateway with the messaging module).
- Network search and account linking with patient OTP approval (Network Hub, Sprint 10).
- Mobile API tokens (Sanctum) for these endpoints — added with the first mobile-facing endpoints.
- Staff invitation screens (Sprint 2 admin work).
