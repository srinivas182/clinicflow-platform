# Developer guide

## 1. Architecture at a glance

- **Laravel 13 / PHP 8.4** modular monolith; **React 19 + TypeScript + Inertia 3**, Tailwind 4; REST API under `/api/v1` (API keys with scopes) and a FHIR R4 read API.
- **Multi-tenancy** (stancl/tenancy): one MySQL database per practice; the **platform** database (practices, users, packages, brands, storage targets…) and a **network hub** database (`hub` connection) shared across practices. See [ADR 0004](../adr/0004-database-per-provider-tenancy.md).
- **Domains** in `app/Domains/<Domain>` (Actions, Http, Models, Support, Events, Jobs, Console) — e.g. Visits, Clinical, Lab, Billing, Telemedicine, Scribe, Reports, Platform, Identity.
- **Files** always through the `files` disk (`FileStore::DISK`) — local or S3-compatible, chosen by the super admin; each practice gets its own folder prefix.
- **Background work**: Redis queues (`messages`, `ai`, `default`/`exports`) run by Horizon; jobs dispatched in a practice run in that practice's database.
- **Real time**: Reverb, private practice-scoped channels (`routes/channels.php`); patients and the display sign in via `RealtimeAuthController`. Events carry ids only.

## 2. Security building blocks

| Concern | Where |
|---|---|
| Authenticator 2FA, recovery codes, trusted devices | `Identity/Actions/Authenticator`, `TrustedDevices`, `Support/Totp` (RFC 6238) |
| Step-up confirmation | `step-up` middleware (`RequireRecentConfirmation`) on sensitive routes |
| Lockout, record-access alerts | `StartLogin::checkPassword`, `record-access` middleware (`TrackRecordAccess`) |
| Headers / CSP (nonce) | `SecurityHeaders` middleware |
| Upload virus scanning | `ScanUploads` middleware + `VirusScanner` (ClamAV) |
| HTML sanitising | `TemplateRenderer::sanitise` (allowlist, HTML5 parser) |
| Encryption | `encrypted` casts; key rotation with `APP_PREVIOUS_KEYS` + `security:reencrypt` |

Add `->middleware('step-up')` to any new route that exports data, creates credentials or widens access.

## 3. Conventions

- Controllers stay thin; logic in Actions. Validate at the edge; authorise with permissions (`Permission::*`).
- Platform tables via `DB::connection(config('tenancy.database.central_connection'))`, never the default connection.
- Long lists use `->paginate()->withQueryString()->through(...)` and the `Pager` component.
- Settings via `Setting::get/put` (practice) and `WalletSettings::get/put` (platform) — cached; never write those tables directly.
- New encrypted data is picked up by key rotation automatically (values are found by format).

## 4. Testing and quality gates

- **Pest** feature tests run against real MySQL (tenancy needs it); unit tests where possible. Hub tables are created automatically before each feature test.
- Local before every push: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 8), `npx tsc --noEmit`, `npm test`, and the new plus affected tests.
- When changing sign-in, session or auth middleware, also run `tests/Feature/Identity/LoginTest.php`, `AuthenticatorTest`, `TrustedDevicesTest` and `SessionSecurityTest`.
- Test settings that default on in production are off in `phpunit.xml` (authenticator requirement, leaked-password check); tests that cover them switch them on.

## 5. CI and delivery

- Feature branch → **draft PR** (lint and static analysis only) → mark **ready** → one full run (4 parallel test parts, front end, Composer and npm vulnerability scans) → merge when fully green → no repeat run on main.
- A failed part posts a PR comment with the **first failure** at the top.
- GitHub Actions rule: status functions (`success()`, `failure()`, `cancelled()`) only in `if:` conditions; use `job.status` inside scripts.
- Version in `config/clinicflow.php` and `CHANGELOG.md` per sprint; sprint record in `docs/sprints/`.

## 6. Useful commands

`storage:copy-to-active`, `security:check`, `security:reencrypt`, `data:prune`, `reports:send`, `scribe:purge`, `queue:alert` — see the [administrator guide](administrator-guide.md#8-scheduled-commands-run-by-the-scheduler) for the scheduled set.
