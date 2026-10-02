# Sprint 4C — Automatic subscription payments

| Story | Acceptance | Status |
|---|---|---|
| Consent | Only the owner can switch on; explicit tick; consent user, IP and time stored; no consent means no saved card | Done |
| Saved cards | Token only (encrypted), brand, last 4, expiry; new card replaces old | Done |
| Paystack | Card-only checkout on setup; reusable authorisation saved; charged on due date | Done |
| Peach Payments | `createRegistration` on setup; merchant-initiated charge on the registration | Done |
| PayFast | PayFast subscription (monthly/annual); later ITNs settle the next open invoice; duplicates ignored | Done |
| Yoco | Not available (no recurring billing); clear message; pay-by-link continues | Done |
| Failures | Three attempts, two days apart; owner emailed each time; read-only rule still applies after grace | Done |
| Switch off | Card removed at the gateway; never charged again by Clinic Flow | Done |

## Needs a sandbox run before go-live
- PayFast subscription cancel API signature and the field names of later recurring ITNs.
- Peach Checkout V2 `createRegistration` response fields and the recurring API with the client's recurring entity.
- Paystack charge-authorisation with a test card.
