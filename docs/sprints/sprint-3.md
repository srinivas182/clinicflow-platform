# Sprint 3 — Front desk, queue, invoicing and payments

**Squad A:** queue and visit stages, front desk, kiosk, TV display, live updates.
**Squad B:** invoicing, payment timing, provider payment gateway, refunds.

| Story | Acceptance | Status |
|---|---|---|
| Server-enforced visit lifecycle | Only allowed moves; Done and Left are final; every move recorded | Done |
| Tickets | A001… per provider per day, issued by the server | Done |
| Check-in | One open visit per patient per day; booking checked in; invoice opened with consult fee | Done |
| Payment timing | Cash pays before triage when the clinic chooses; medical aid goes straight through | Done |
| Payments | Card/EFT need a reference; no overpayment; partial and full; paid lines locked | Done |
| Pay links | Pending until the gateway confirms | Done |
| Refunds | Reason required; never more than paid; only roles with `billing.refund` | Done |
| Leaving the queue | Only while waiting; four reasons; prepaid fee flagged by refund rule; booking marked no-show | Done |
| Kiosk and display | Secret device links; kiosk for booked patients; display shows ticket numbers only | Done |

## Deferred
- Real gateway adapters (Paystack, PayFast, Peach, Yoco) and their webhooks — once Sekal chooses the first gateway.
- Reverb server and private-channel authorisation — with the deployment work; screens poll in the meantime.
- Triage colours and doctor routing — Sprint 4.
