# Domain modules

Dr Business Flow is a modular monolith (ADR 0003). Each folder is one business domain.

| Module | Responsibility |
|---|---|
| Platform | Providers (tenants), packages, subscriptions, verification, domains, platform admin. Lives in the Platform database. |
| Identity | Sign-in, MFA, roles and permissions, workspace switcher, audit log. |
| Hub | Network Hub: patient identity, consent register, e-script registry, referral routing, provider directory. Hub database only. |
| Patients | Patient registry, registration, SA ID checks, dependants, consent. |
| Scheduling | Appointments, rosters, rooms, reminders. |
| Visits | Queue, visit state machine, front desk, triage, multi-doctor routing, displays. |
| Clinical | Consultations, notes, ICD-10, documents, procedures, chronic and preventive care. |
| Prescribing | Scripts, safety checks, advanced electronic signatures, script versions. |
| Pharmacy | Dispensing, owing list, S5/S6 register, stock and procurement, dispatch. |
| Lab | Lab orders, samples, results, reports and release. |
| Billing | Invoices, payment timing, payments through provider-owned gateways, refunds, discharge gate. |
| Claims | Medical aid eligibility, claims, remittances, pre-authorisation. |
| Finance | Ledger, cash-up, revenue dashboards, doctor earnings, accounting sync. |
| Telemedicine | Video, audio and chat consults (LiveKit, Reverb). |
| Wallet | Provider prepaid wallet, metering, thresholds, top-ups. |
| Messaging | Email + SMS (one charge), WhatsApp, push notifications, templates. |
| Documents | Template studio and branded PDF rendering. |

## Rules

1. A module talks to another module through its Actions or events, never by reaching into its models' tables.
2. Controllers stay thin: validate, call one Action, return a response or resource.
3. Provider data lives in the provider database. Only cross-provider data goes to the Hub.
4. Every file declares `strict_types` (enforced by tests/Arch).
