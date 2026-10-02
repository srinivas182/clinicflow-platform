# Sprint 4B — Payment gateways

Client choice: PayFast, Paystack, Peach Payments and Yoco. Whatever is enabled works, in test or live mode.

| Who | Uses the gateways for | Configured in |
|---|---|---|
| Super admin | Collecting provider subscriptions (and wallet top-ups from Sprint 10) into the platform's own account | Admin → Payments |
| Each provider | Patient pay links into the provider's own account | Settings → Payments (owner only) |

| Story | Acceptance | Status |
|---|---|---|
| Four connectors | Checkout start and verified notifications for each; sandbox and live hosts | Done |
| Secure credentials | Encrypted at rest; secrets write-only; blank secret keeps the stored one | Done |
| Offered list | Super admin can withdraw a gateway; providers can't connect it | Done |
| Pay links | Created when opened; paid only after a verified webhook with matching reference and amount; idempotent | Done |
| Refunds | API refunds for Paystack and Yoco; manual for PayFast and Peach | Done |
| Subscriptions | Invoice 3 days ahead with VAT; paying activates the period and lifts read-only | Done |

## Go-live checklist (per gateway)
1. Create the merchant account and copy the keys from the gateway dashboard.
2. Paste the webhook URL shown in Clinic Flow into the gateway dashboard (Yoco: register the webhook and copy its `whsec_` secret).
3. Run "Test connection", make a sandbox payment, then switch to live.

## Deferred
- Automatic recurring card debits for subscriptions (PayFast subscriptions, Paystack authorisations, Peach tokenisation) — providers pay each invoice for now.
- Card-machine (in-person) integration with Yoco devices.
