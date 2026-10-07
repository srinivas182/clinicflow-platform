# Administrator guide (super admin)

The super admin runs the platform: practices and packages, providers for messaging, payments, AI and storage, white-label brands, and platform security. All admin screens are under **/admin** on the platform domain. Your account must use an **authenticator app** (Account → Security) — it is required for super admins.

## 1. First-time set-up checklist

| Step | Where |
|---|---|
| Sign in, set up your authenticator app, save the recovery codes | Account → Security |
| Packages and prices (what each package includes and offers as add-ons) | /admin/packages |
| Payment gateways and auto-debit | /admin/payments, /admin/auto-debits |
| SMS and email suppliers; WhatsApp | /admin/messaging, /admin/whatsapp |
| File storage (S3 or S3-compatible before running more than one server) | /admin/storage |
| Video (LiveKit) for telemedicine | /admin/telemedicine |
| AI providers and prices (Deepgram/Azure for speech, Claude for notes) | /admin/ai-scribe |
| Accounting export, couriers, calendars | /admin/accounting, /admin/couriers, /admin/calendars |
| Platform website pages and the status page | /admin/pages, /admin/status |
| Run the deployment security self-check | `php artisan security:check` |

## 2. Practices

- **Providers** (/admin/providers): new sign-ups, verification (HPCSA/BHF references), status (active, read-only, suspended).
- **Groups** (/admin/groups): practices that report together (totals only, never patient records).
- **Resellers** (/admin/resellers) and **Brands** (/admin/brands): white-label partners. A brand has its own name, logo, colours, practice domain, verified email sender and approved SMS sender; practices that sign up through the brand link join it.
- **Support** (/admin/support): practices grant time-limited, logged access; you cannot open a practice without it.

## 3. File storage

1. **Add storage** (Amazon S3 Cape Town `af-south-1` recommended, or any S3-compatible provider) and **Test connection** — a target cannot be activated until the test passes.
2. Copy existing files: `php artisan storage:copy-to-active <id> --dry-run`, then without `--dry-run`. Every file is checked by size and SHA-256; nothing is deleted.
3. **Activate**, then run the copy once more to catch files uploaded in between.

Local disk works for a single server only. Local folders must be outside the application (never inside public/).

## 4. AI scribe and lab explanations

- Enable one speech provider (Deepgram Nova-3 Medical or Azure Speech, South Africa North) and the note writer (Claude). Keys are stored encrypted.
- Set prices: add-on monthly fee and included minutes, price per extra minute (charged to the practice wallet), maximum recording length, model for lab explanations.
- Before go-live: legal sign-off on POPIA section 72 (text processed outside South Africa), consent wording and the provider data-processing agreements; clinical sign-off on the draft-note prompt.

## 5. Security

| Setting (.env) | Default | Purpose |
|---|---|---|
| `CLINICFLOW_REQUIRE_AUTHENTICATOR` | true | Super admins, owners and practice admins must use an authenticator app |
| `CLINICFLOW_CSP` / `CLINICFLOW_CSP_REPORT_ONLY` | on in production / false | Content-security policy (report-only for a trial period) |
| `CLINICFLOW_VIRUS_SCAN` / `CLINICFLOW_VIRUS_SCAN_FAIL_CLOSED` | false / true | Scan every upload with ClamAV (`CLAMAV_HOST`, `CLAMAV_PORT`); refuse uploads if the scanner is down |
| `CLINICFLOW_CHECK_LEAKED_PASSWORDS` | true | Refuse breached passwords at sign-up |
| `CLINICFLOW_RECORD_VIEWS_ALERT` / `_LIMIT` | 100 / 300 | Distinct patient records per person per hour before owners are alerted / further records refused |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT` | 30, true | Idle timeout (minutes) and encrypted sessions |

- **After every deployment**: `php artisan security:check` (fails in production if anything is unsafe).
- **Rotate the encryption key** yearly or when someone with server access leaves: see [key rotation](../security/key-rotation.md) (`security:reencrypt`).
- **Failed background jobs** email platform admins hourly; review them in Horizon (**/admin/horizon**).

## 6. Data retention

`data:prune` runs daily. Periods (days, configurable with `RETENTION_*_DAYS`): sign-in and signing codes 7, failed jobs 30, API and webhook logs 90, sign-in history, record views and processed lab messages 365, message log 730, audit and patient-access logs 2555 (7 years). **Clinical records are never removed.** Confirm the periods with the legal reviewer.

## 7. Real time and background work

- Real-time updates: `BROADCAST_CONNECTION=reverb` with `REVERB_*` settings; screens fall back to polling if it is off.
- Queues: `QUEUE_CONNECTION=redis` with Horizon workers for `messages`, `ai` and `default`/`exports` queues.

## 8. Scheduled commands (run by the scheduler)

| Command | What it does |
|---|---|
| `subscriptions:invoice`, `subscriptions:collect`, `subscriptions:enforce` | Platform billing |
| `wallet:auto-topup` | Wallet top-ups |
| `reports:send` | Scheduled report emails |
| `scribe:purge` | Delete AI drafts after 30 days and leftover audio |
| `data:prune` | Retention |
| `queue:alert` | Hourly failed-job alerts |
| `webhooks:deliver`, `status:check` | Webhook retries; status page checks |
| `care:tick`, `lab:release-tick`, `telemedicine:tick`, `locums:remind`, `packages:expire`, `feedback:request` | Clinical and service reminders |
| `pharmacy:publish-stock`, `pharmacy:return-uncollected`, `claims:remittances`, `accounting:export`, `calendar:busy` | Pharmacy, claims, accounting and calendar sync |
