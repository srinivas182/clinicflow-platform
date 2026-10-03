# Sprint 14B — WhatsApp, couriers, pharmacy comparison

| Story | Acceptance | Status |
|---|---|---|
| WhatsApp supplier | Meta / Twilio / Clickatell, one active; template approvals; prices per category | Done |
| WhatsApp add-on | Practice switches on; wallet-paid per message; opt-in; SMS fallback | Done |
| Couriers | Super admin enables Pargo / TCG / Skynet; practice links own account; manual courier | Done |
| Deliveries | Fee rule with threshold; S5+ guard; tracking; proof-of-delivery code | Done |
| Pharmacy comparison | Opt-in stock publishing; availability and estimated total per pharmacy | Done |

## Before go-live
- Register the WhatsApp Business account and phone number with the chosen supplier; submit templates; confirm payloads in sandbox.
- Courier APIs: connect each courier with sandbox credentials; until then bookings are made in the courier's portal.
- Legal/pharmacy reviewer confirms the rules for delivering scheduled medicine.

## Review fixes (before merge)
- WhatsApp charges use a unique reference per message, so batches (statements, recalls) sending several in one second are each charged and none fails.
- Recording WhatsApp opt-in makes WhatsApp the patient's channel (withdrawing returns them to SMS).
- Patients can opt in to or stop WhatsApp themselves on the portal's My care page.

## Known limitation
- Courier booking is manual: staff book with Pargo, The Courier Guy or Skynet and enter the waybill number; delivery is confirmed with the patient's code.
  Automatic booking through each courier's API is added once the client provides sandbox credentials and API documentation (the per-courier "API ready" flag stays off until then).
