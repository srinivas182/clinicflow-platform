# Sprint 5 — Consultations, prescribing and medical aid claims

| Story | Acceptance | Status |
|---|---|---|
| Consult notes | Only the calling doctor; SOAP; ICD-10 validated; one primary; stale saves refused | Done |
| Safety checks | Allergy and schedule limits block; interactions/duplicates need a reason; current medicines checked | Done |
| PIN signing | Prescriber only; correct PIN; safety re-checked; hash stored; version frozen; PDF issued | Done |
| Versions | Change = new version with reason; newest signed version is the only dispensable one | Done |
| Complete consult | Primary diagnosis required; no unsigned draft; visit routed to Pharmacy or Done | Done |
| Eligibility | Front desk check through the switch; result stored and audited | Done |
| Claims | Built from invoice + diagnoses; rejected claims fixed and resubmitted; accepted claims locked | Done |

## Before go-live (client decisions)
- Drug database licence (MIMS or MediKredit) — replaces `DemoDrugDatabase`. The demo data is not for clinical use.
- Switching house (e.g. MediKredit, Healthbridge, Altron HealthTech) — replaces `DemoClaimsSwitch`; remittances arrive in Sprint 6.
- Full SA ICD-10 MIT import.
- Pharmacy reviewer to confirm repeat limits for S2 and S5 (only S3/S4 and S6 are enforced now) and the interaction rules.
