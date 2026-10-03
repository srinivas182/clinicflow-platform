# Changelog

All notable changes to Clinic Flow are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.11.0] — Sprint 8: patient portal, legacy import, compliance centre and public website

### Added
- Patient web portal on each provider's address (`/my`): sign in with cell number and SMS code (no hint whether a number is registered); one cell manages its own profile and those it is guardian for; today's ticket, place in the queue and collection code; book and cancel appointments (once the practice is verified); released lab results with the doctor's comment; invoices with online payment; signed scripts.
- Legacy patient import from CSV: row-by-row validation (SA ID check digit, date of birth, SA cell numbers including +27), duplicates skipped with reasons, import history; imported patients must give POPIA and treatment consent at their next check-in (enforced).
- Audit and compliance centre (owner, manager, practice admin): search and filter the audit log, CSV export (itself audited), and a POPIA patient-data export with the patient's access log.
- Public website: CMS pages managed by the super admin (HTML sanitised on save and display; a published "home" page replaces the default home page) and a "Find care" directory listing verified providers only.

## [0.10.0] — Sprint 7: in-house lab, results inbox, finance and messaging

### Added
- In-house lab: doctors order tests from the consult (billed to the visit and attributed to the ordering doctor); sample collection with barcode; results auto-flagged against reference and critical limits; verification by a second person.
- Results inbox: critical results first; the ordering doctor acknowledges critical values (with the action taken), reviews with a comment and releases to the patient. The patient gets an SMS that results are ready — never the values.
- Credit notes: credit part of an invoice without editing paid lines; overpayments are flagged for refund. Uncollected medicine now issues a credit note automatically.
- Double-entry ledger (append-only), posted automatically for invoice lines, payments, refunds and credit notes.
- Cash-up per cashier: expected takings by method (less cash refunds), counted cash, reason for any difference; once per day; locks the day's payments.
- Finance dashboard (owner, manager): takings today by method, revenue by doctor and source (consults and procedures to the seeing doctor, medicines to the prescriber, lab to the ordering doctor), unpaid claims by age, recent cash-ups, messages used.
- Messaging: email and SMS share one monthly allowance per package (each email or 160-character SMS segment is one message); every message is logged; usage above the allowance is charged on the next subscription invoice.
- Permissions `lab.process` (lab technician) and `finance.view` (owner, manager).

## [0.9.0] — Sprint 6: pharmacy, dispatch, discharge gate, remittances and quotes

### Added
- In-house pharmacy: stock by batch and expiry; dispensing of the newest signed script version only, earliest expiry first, never expired stock; shortfalls go on the owing list and are billed only when supplied; dispensed medicines are added to the visit invoice with their NAPPI codes.
- Append-only S5/S6 register: every receipt, dispensing and return with balance, patient, prescriber and pharmacist.
- Pharmacist query sends the visit back to the doctor; the doctor answers with a new script version.
- Collection window: 4-digit collection code, collector name and ID when someone else collects; uncollected medicine returns to stock after the clinic's collection window (default 7 days) and the invoice is flagged for a credit.
- Discharge gate: a visit closes only when the patient owes nothing (medical aid patients with a submitted or accepted claim owe only co-payments after remittance). Owners and managers can override with a reason; every override is logged.
- Completing a consult without a script sends the patient to pay at the front desk when a balance is due; the doctor's queue is free for the next patient.
- Remittances: scheme payments recorded on invoices (new payment method "Medical aid"), claims marked paid or part-paid, shortfalls flagged as patient co-payments; each remittance applied once (hourly `claims:remittances`).
- Claim ageing by 0–30, 31–60, 61–90 and 90+ days.
- Procedure quotes: prepared by the doctor; accepting adds the procedure lines to the invoice; medical aid patients need a pre-authorisation number.
- Permissions `pharmacy.dispense` (pharmacist) and `discharge.override` (owner, manager).

## [0.8.0] — Sprint 5: consultations, prescribing and medical aid claims

### Added
- Consult screen for the doctor who called the patient: SOAP notes, ICD-10 diagnoses (one primary; codes validated against the reference list), triage summary and allergy banner. Saves use optimistic locking — a stale save is refused instead of overwriting another user's change.
- Prescribing with safety checks: allergy (ingredient or class) and schedule repeat limits (S6 none; S3/S4 at most five) must be fixed; interactions and duplicates (within the script and with the patient's current medicines) need a written reason.
- Signing with a one-time PIN sent to the prescriber's phone (advanced electronic signature): only the prescribing doctor can sign, safety is re-checked at signing, a SHA-256 signature hash is stored, the version is frozen and the branded prescription PDF is issued with its template version.
- Script versions: any change after signing opens the next version with a reason; signing it supersedes the previous one; only the newest signed version is dispensable.
- Completing a consult needs a primary diagnosis and no unsigned draft; the visit moves to Pharmacy when a script was signed, otherwise to Done.
- Medical aid eligibility checks at the front desk and a claims worklist: claims are built from the invoice (tariff and NAPPI codes) and the consult's ICD-10 codes (primary first), submitted to the switch, and resubmitted after a rejection is fixed.
- `DrugDatabase` and `ClaimsSwitch` contracts with DEMO implementations (small ICD-10 set, demo medicine catalogue and interaction rules, deterministic test switch) until the client licenses a drug database and chooses a switching house.
- Permissions `consults.write` (doctors and locums only — cannot be granted to other roles) and `claims.manage` (billing clerk, manager, owner).

## [0.7.0] — Sprint 4C: automatic subscription payments (auto-debit)

### Added
- Auto-debit for provider subscriptions with PayFast, Paystack and Peach Payments (Yoco has no recurring billing, so Yoco stays pay-by-link and the screen says so).
- Owner-only consent: "Pay and turn on automatic payment" on Settings → Subscription saves the card used for that invoice; consent, user and IP are recorded. Paying without consent never saves a card.
- Saved-card mandates in the Platform database hold only the gateway's encrypted token, card brand, last four digits and expiry — never card numbers. A new card replaces the old one.
- Daily `subscriptions:collect` (06:00): Paystack (charge authorisation) and Peach (recurring registration, merchant-initiated) cards are charged on the due date; up to three attempts two days apart; owner emailed after every attempt, and told when retries stop.
- PayFast runs the subscription itself (monthly or annual, no end date); each later charge arrives by ITN and settles the oldest open invoice of the same amount; repeated ITNs are ignored.
- Switch off at any time: the card is removed at the gateway (Paystack deactivate, Peach registration delete, PayFast subscription cancel) and Clinic Flow never charges it again, even if the gateway does not confirm.
- Super admin → Auto-debit: providers on auto-debit, gateway, card label, last charge and failures.
- Peach settings gain optional "Recurring entity ID" and "Access token" for auto-debit.

## [0.6.0] — Sprint 4B: payment gateways (PayFast, Paystack, Peach Payments, Yoco)

### Added
- Gateway connectors for PayFast (signed form + ITN with signature and server validation), Paystack (initialise + HMAC-SHA512 webhooks), Peach Payments Hosted Checkout V2 (OAuth token + webhook re-confirmation with Peach) and Yoco Checkout (Standard Webhooks signatures). Each works in test (sandbox) or live mode.
- Provider Settings → Payments (owner only, new `payments.configure` permission): connect any offered gateway, test or live, default for pay links, test connection, webhook URL to paste into the gateway. Credentials are encrypted in the provider's own database; secrets are write-only and never sent back to the browser.
- Super admin → Payments: the platform's own accounts for subscription billing, and which gateways providers may connect.
- Patient pay links on the provider's domain (`/pay/{token}`): the gateway checkout is created when the patient opens the link (safe for SMS/WhatsApp previews). Payments count only after a verified webhook whose reference and amount match; repeated webhooks are ignored.
- Refunds through the API for Paystack and Yoco (live keys); PayFast and Peach refunds are made in their dashboards and recorded as manual refunds.
- Subscription billing: daily `subscriptions:invoice` issues invoices (package price + 15% VAT) 3 days before each period; providers pay from Settings → Subscription through the platform gateway; payment activates the subscription for the period and reopens read-only providers.
- The fake gateway is now only used when `PAYMENTS_ALLOW_FAKE=true` (local and tests), never in production.

## [0.5.0] — Sprint 4: triage, routing, red alerts and document templates

### Added
- Triage capture: vitals (BP, pulse, temperature, SpO2, respiratory rate, glucose, weight, height), presenting complaint and nurse-assigned colour (red, orange, yellow, green) with a live server-side colour suggestion; the nurse always decides.
- Allergy register per patient: duplicates merged, removal only with a reason, shown on every clinical screen.
- Doctor queue routing: bookings first, then patients who asked for that doctor, then the shared pool ordered by colour and wait time; a doctor cannot call a second patient while one is with them.
- Red triage alerts: `RedTriageAlert` broadcast to every on-duty doctor; first to accept owns the patient, others are refused.
- Document template studio: invoice, prescription, sick note and referral templates with safe merge fields and lists, HTML sanitising, versioning, A4/A5 paper and live preview; the legal block (practice and HPCSA details, signature statement) is always added.
- PDF rendering with dompdf; every issued document records the template version used. Branded invoice PDF download.
- Default templates seeded for every new provider during provisioning.
- Permission `triage.record`; the template studio uses `settings.manage`.

## [0.4.0] — Sprint 3: front desk, queue, invoicing and payments

### Added
- Visit lifecycle enforced on the server: Checked in → Triage → Doctor → Pharmacy/Dispatch → Done, or Left; pharmacist query and partner refusal loop back to the doctor; every move timestamped for wait-time reporting.
- Daily queue tickets (A001…) issued by the server, never duplicated.
- Front desk: search-first check-in (booked or walk-in, cash or medical aid, preferred doctor), live queue refreshed every 15 seconds, stage moves, removal with one of four reasons.
- Invoices open at check-in with the consult fee; lines added through the visit; paid lines are locked and must be refunded, never silently changed.
- Payment timing setting: cash patients pay the consult fee before triage, or at the end; medical aid patients go straight through.
- Payments into the provider's own account: cash, card machine (slip reference), EFT, pay link via the provider's gateway (pending until confirmed). Partial and full payments.
- Refunds with a reason, never more than was paid; refund rule (refund, credit, none) flags prepaid fees when a patient leaves before being seen.
- Self check-in kiosk (booked patients enter their cell number) and waiting-room display (ticket numbers only), both on secret device links.
- `PaymentGateway` contract with a fake gateway for local and test use; real adapters plug in per provider.
- Live-update event `VisitStageChanged` on the provider's private queue channel (Reverb); broadcasting configuration.
- Permissions `visits.manage`, `billing.collect`, `billing.refund`.

## [0.3.0] — Sprint 2: onboarding, packages, rosters and appointments

### Added
- Self-service provider sign-up: practice type, free subdomain (`<name>.clinicflow.co.za`, reserved words blocked), package choice, owner account, registration numbers; creates the provider database, 30-day trial and verification checklist. Independent doctors are owner and prescriber.
- Package builder and subscriptions: seeded sample packages per provider type, public pricing page read live from active packages, admin price/trial/active editing.
- Super admin console: providers list, registration checks (verify/reject), approval that requires every check for the provider type.
- Daily `subscriptions:enforce`: expired trials and unpaid subscriptions become read-only after a 7-day grace period; read-only providers can view but not change anything (HTTP 423). Data is never deleted.
- Rooms and rosters with clash rules (no person or room in two sessions at once; 10–60 minute slots).
- Appointments: free-slot calculation, booking only into real slots of a rostered doctor, no double booking of doctor or patient, cancellation with reason; video/audio/chat wait for the telemedicine module.
- New permissions `appointments.view`, `appointments.book`, `rosters.manage`; `providers:sync-roles` command updates existing providers' role templates.
- GitHub Actions bumped (checkout 7, cache 6, setup-node 7).

## [0.2.0] — Sprint 1: identity and patient registry

### Added
- Sign-in on the central domain with email or cell number + password, then a 6-digit one-time code (5-minute expiry, 5 attempts, rate limited).
- Workspaces: memberships link one account to many providers; locum access expires automatically; "Where are you working today?" page.
- Single-use, 60-second handoff links carry a signed-in user to the provider's own domain.
- Role templates per provider type (11 roles) with a permission catalogue stored in each provider database; only doctors and locum doctors can ever sign scripts.
- Append-only audit log (sign-ins, workspace opens, staff changes, patient registration) in the Platform and provider databases.
- Patient registry: SA ID check digit with date of birth and sex, passports and permits, encrypted ID numbers with keyed-hash search, age-based consent rules (under 12, 12–17, adults), cell or "no cellphone", POPIA and treatment consent, duplicate prevention, search by SA ID, cell or name.
- Pages: sign in, enter code, choose workspace, patients list with search, register patient.

## [0.1.0] — Sprint 0 foundations

### Added
- Laravel 13 / PHP 8.4 application skeleton with React 19 + TypeScript + Inertia.js 3 front end.
- Database-per-provider tenancy (stancl/tenancy 3): provider model, automatic database creation, migration and deletion; Platform and Network Hub connections.
- REST API v1 with `GET /api/v1/health`.
- Clinic Flow design system: colour tokens, IBM Plex Sans, Button, Badge, Card, KPI, queue Ticket and triage components.
- Domain module structure for all 17 business domains.
- Quality gates: Pint, PHPStan level 8 (Larastan), Pest 5 (feature, unit, tenancy and architecture tests), Vitest.
- CI workflows for back end and front end with path filters, caching and cancellation.
- Local Docker stack: PHP 8.4 FPM, Nginx, MySQL 8.4, Redis 7, Mailpit.
- Architecture decision records 0001–0010, contribution guide, security policy.
