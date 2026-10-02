# Changelog

All notable changes to Clinic Flow are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
