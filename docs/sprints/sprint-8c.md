# Sprint 8C — SMS and email suppliers, templates and allowances

| Story | Acceptance | Status |
|---|---|---|
| Suppliers (super admin only) | 4 SMS + 2 email suppliers; test/live; default per channel; credentials encrypted; test send | Done |
| Test mode safety | Only test recipients receive messages; others suppressed | Done |
| Templates | Catalogue with allowed placeholders; super admin defaults in 4 languages | Done |
| Provider control | From-name, reply-to; edit included, editable messages only; security texts locked | Done |
| Package allowances | SMS and email counted separately; overage per channel on the subscription invoice; 80%/100% alerts | Done |
| Opt-outs | Marketing messages skipped for opted-out recipients | Done |

## Before go-live
- Choose the SMS and email suppliers and run a test send for each from Admin → Messaging.
- Supplier APIs were implemented from public documentation; confirm each in its sandbox.
- Register an approved SMS sender ID with the chosen supplier if branded senders are wanted.
- Set up SPF/DKIM for the platform sending domain.
