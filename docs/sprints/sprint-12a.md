# Sprint 12A — Online consults done properly

| Story | Acceptance | Status |
|---|---|---|
| Availability | Per doctor and mode; weekly hours; exceptions; buffer | Done |
| Price table | Per mode and duration; 15-minute minimum; doctor overrides | Done |
| Paid booking | Portal and front desk; hold while paying; confirm on payment; holds expire | Done |
| Virtual visit | Notes, ICD-10, prescribing, e-scripts and invoices for online consults | Done |
| Join rules | Waiting screen and device test; join at start; late join within time; warning; grace; close | Done |
| Extensions | Doctor adds time; patient pays first; wallet reserves more | Done |
| Cancellation and refunds | Policy; refunds through the practice gateway; tasks where the gateway has no refund API | Done |
| Chat consults | Time-boxed live chat; charged only if both took part; follow-up window | Done |

## Notes
- Chat screens refresh every 4 seconds. Moving them to instant push over Laravel Reverb is part of the hardening sprint.
- Online consults are paid at booking; medical aid claiming for online consults can be added later if required.
