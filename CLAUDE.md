# Clinic Flow — guide for AI-assisted work

Read this before changing code in this repository.

## Product

Healthcare network SaaS for South Africa (client: Sekal, Training Young Minds; builder: Mayura Consultancy Services).
Providers — clinics, independent doctors, pharmacies, labs — subscribe; patients use one account across all of them.
Scope, prototypes and sprint plan are in the project documents (Scope v1.0, Web/Mobile prototypes v1.0, Technology and Sprint Plan v1.0).

## Non-negotiable architecture rules

1. **Database per provider.** Provider data (patients, visits, scripts, invoices, money) lives only in that provider's database. Use tenancy context (`tenancy()->initialize($provider)`), never cross-database joins.
2. **Network Hub** (`hub` connection) holds only cross-provider data: identity, consent, e-script registry, referrals, directory. Never clinical notes.
3. **Platform database** holds providers, domains, packages, subscriptions, platform users.
4. **The platform never holds patient money.** Patient payments go to provider-owned gateways via the `PaymentGateway` adapter.
5. **Modules** in `app/Domains/<Module>`; thin controllers → one Action; modules talk via Actions or events.
6. **API first.** Every feature the mobile apps need is exposed under `/api/v1` and calls the same Actions as the web.
7. **Data stays in RSA** (AWS af-south-1). No third-party service that stores patient data outside South Africa without an ADR.
8. Red/orange/yellow/green colours are reserved for triage and status in the UI.

## Commands

- `composer quality` — Pint, PHPStan level 8, Pest.
- `npm run typecheck && npm test && npm run build`.
- Provider migrations: `database/migrations/tenant`; run `php artisan tenants:migrate`.
- Hub migrations: `php artisan migrate --database=hub --path=database/migrations/hub`.

## Conventions

- PHP: `declare(strict_types=1);`, typed properties and returns, enums for statuses, PHPStan level 8 clean.
- Tests: Pest. Tenancy tests use `DatabaseMigrations` and MySQL. Fake South African data only.
- Front end: React + TypeScript strict, components in `resources/js/components/ui`, pages in `resources/js/pages`, Tailwind tokens from `resources/css/app.css`.
- Commits: Conventional Commits. All changes via pull request; only the tech lead merges.
- Record significant decisions as an ADR in `docs/adr`.
