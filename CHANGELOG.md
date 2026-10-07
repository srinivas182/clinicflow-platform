# Changelog

All notable changes to Clinic Flow are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.57.0] — Sprint L3: staff management

### Added
- Staff page (owners and practice admins): invite people by email and/or mobile with a role and branches; see pending invitations (send again, withdraw); see the team with role, branches, authenticator use, last sign-in and status; change role and branches; suspend or restore access (suspension takes effect immediately).
- Invitations: single-use links valid for 7 days (only a hash is stored); sending again replaces the link. A new person creates an account (same password rules as sign-up) and signs in normally; an existing account signs in and accepts. A link only works for the email or mobile it was sent to.
- Rules: nobody can change or suspend themselves; the owner cannot be changed here and the owner role cannot be given by invitation; roles must suit the practice type. Every change needs step-up confirmation and is audited.

## [0.56.0] — Sprint L1: Excel export, safer CSV and remaining pagination

### Added
- Report builder: Export Excel (.xlsx) — bold header, money formatted to 2 decimals, text stored as plain text (never as a formula). Built in, no extra library.

### Security
- CSV exports neutralise cells that a spreadsheet would run as a formula (starting with =, +, -, @), e.g. from names or free text; numbers are unchanged.

### Changed
- Pagination for referrals, patients' prepaid packages, website reviews and the practice's locum shifts.
- Locum shifts: applications are loaded in one query per page instead of one per shift.

### Notes
- N+1 review: list pages already load related records up front; the remaining per-row queries (analytics per doctor, group dashboard) are inherent and cached.

## [0.55.0] — Sprint H2-2b: indexes, caching and the load-test plan

### Added
- Indexes for reports, analytics and retention (only added where missing): invoices, payments, appointments, visits (per doctor), lab orders, claims, prescriptions, message log (which had none), record views, webhook deliveries, audit and patient-access logs; platform sign-in history, sign-in codes and audit log.
- Analytics dashboard figures and the group dashboard are cached for 5 minutes per practice/group, period and branch.
- Load-test plan (docs/performance/load-test-plan.md) with targets (p95 under 400 ms reads, 800 ms writes, errors under 0.5 %, real-time within 2 s) and sizing (100,000 users ≈ 3,300 requests/second average), plus k6 scripts for public pages, the public API and staff screens (tests/load/), to run on staging at deployment.

## [0.54.0] — Sprint H2-2a: real-time updates for patients and the waiting-room display

### Added
- Patients (portal): today's visit status updates live as they move through the queue; chat consult messages appear instantly; on video and audio calls, AI scribe consent requests and progress and paid extensions reach both screens instantly (the 5-second check becomes a 30-second safety net while live).
- Waiting-room display updates the moment a ticket is called (it receives ticket numbers only, never names).
- Channel sign-in for non-staff: patients can listen only to their own patient, chat and call channels (checked against their portal session); a paired display only to its practice's queue (checked with its device token).

### Notes
- When real-time is off or disconnected, every screen refreshes as before.

## [0.53.0] — Sprint H2-1: real-time updates for staff (Reverb)

### Added
- Real-time updates with Laravel Reverb: front desk, triage and doctor queue screens refresh the moment a patient checks in or moves stage, and staff see new chat consult messages instantly. Channels are private and scoped to one practice: only its active staff may listen, and only on its own domain. Events carry identifiers only (no message text). A slow 60-second refresh remains as a safety net; when real-time is off or disconnected, screens refresh as before.
- Reverb configuration (config/reverb.php, REVERB_* settings); the content-security policy allows the Reverb address when real-time is on.

### Notes
- Production: BROADCAST_CONNECTION=reverb with queue workers running, so a Reverb outage never blocks check-ins or messages.
- Adds laravel-echo and pusher-js (front end, loaded only when real-time is on).
- Next (H2-2): patient side (portal chat, call screen, portal home), waiting-room display, caching and index tuning, load-test plan.

## [0.52.0] — Sprint H1d: key rotation, data retention and reseller page fix

### Added
- Encryption key rotation without downtime: set the new APP_KEY with the old one in APP_PREVIOUS_KEYS, then `security:reencrypt` (with `--dry-run` first) re-encrypts every stored encrypted value — platform, network hub and every practice database — under the new key. Values are found by their encrypted format, so new encrypted fields are covered automatically; it reports anything unreadable and fails if so. Guide: docs/security/key-rotation.md.
- Data retention (`data:prune`, daily): sign-in and signing codes 7 days, failed jobs 30 days, API and webhook logs 90 days, sign-in history, record views and processed lab messages 1 year, message log 2 years, audit and patient-access logs 7 years; expired trusted devices removed. Clinical records are never removed. All periods configurable.

### Fixed
- The reseller admin and partner portal read platform tables through the platform connection explicitly.

## [0.51.0] — Sprint H1c-2: allowlist HTML sanitiser and upload virus scanning

### Changed
- The HTML sanitiser (website sections, CMS pages, document templates) is now an allowlist built on PHP 8.4's HTML5 parser: only known-safe tags and attributes are kept; scripts, styles, frames, forms, SVG/MathML, event handlers and style attributes are removed; every link is checked after decoding (relative, https/http, mailto, tel only) and images must be relative or embedded image data (no remote images). Document list blocks ({{#lines}}…{{/lines}}) stay intact inside tables. Closes bypasses of the previous filter (handlers without a space, unquoted or encoded javascript: links, SVG, nested tags).

### Added
- Virus scanning of every upload with ClamAV, before any page handles the file: infected files are refused, nothing is stored, and the attempt is logged and audited; if the scanner is down, uploads are refused (configurable). ClamAV runs as its own container (docker-compose); enable with CLINICFLOW_VIRUS_SCAN=true once it is running. security:check flags it when off.

## [0.50.0] — Sprint H1c-1: security headers, web-server rules and dependency scanning

### Added
- Security headers on every page: content-security policy (scripts only from this site or with the request's nonce; no plugins; no framing by other sites; video server allowed), HSTS in production, nosniff, referrer policy, frame protection, and a permissions policy allowing camera and microphone only for this site. The policy is on in production (CLINICFLOW_CSP to force, CLINICFLOW_CSP_REPORT_ONLY for a trial period). The payment redirect page carries the nonce so payments keep working.
- `security:check`: deployment self-check (debug off, HTTPS, secure and encrypted sessions, session timeout, policy on, authenticator for admins); fails in production if anything is unsafe.
- Production never shows debug pages: APP_DEBUG is forced off (and logged) if it is left on.
- CI: dependency vulnerability scans — `composer audit` (backend) and `npm audit` for high/critical issues (front end).

### Changed
- nginx: only index.php can run (any other .php returns 404); backups, archives, logs, dumps and config files are never served; server version hidden; per-address request limit (20/s, burst 60).

## [0.49.0] — Sprint H1b: protection against data theft

### Added
- Per-account lockout: 10 wrong passwords in 30 minutes, from any addresses, locks the account for 15 minutes (even the correct password is refused); the user is emailed once; a successful sign-in resets the count.
- Patient-record access tracking on the care chart, triage, consultation, patient data export and break-glass pages. When one person opens 100 or more different patients in an hour the practice owners are emailed (at most once an hour per person) and it is audited; at 300 further records are refused. Thresholds are configurable.
- Every patient data export emails the practice owners.
- Patient search limited to 60 per minute per person.
- Sign-up refuses passwords known from data breaches (privacy-preserving check: only the first 5 characters of the password's hash are sent).

## [0.48.0] — Sprint H1a-3: trusted devices and practice-wide authenticator rule

### Added
- "Trust this device for 30 days" on the sign-in code screen: the password is still required, but the code is skipped on that device. Only a hash of the device token is stored; the cookie is encrypted and HTTP-only. Super admins always enter a code. Trusted devices are listed on Account → Security and can be removed one by one; "Sign out other devices" removes them all.
- Practice Settings → Security (owner only, after confirming identity): require an authenticator app for all staff, with a count of staff not yet set up.

### Fixed
- The practice security page reads the rule fresh rather than from the cached practice record.

## [0.47.0] — Sprint H1a-2: step-up confirmation and session hardening

### Added
- "Confirm it's you" before sensitive actions — patient and audit exports, report exports, finance and accounting exports, API key creation, break-glass requests and approvals, and granting support access: the password (or an authenticator code for staff who use the app) is re-entered, valid for 15 minutes.
- Sign-in history on Account → Security (last 10, with new devices marked) and an email to the user when they sign in from a browser or device not seen before.
- "Sign out other devices" (needs the password); other sessions end on their next request.

### Changed
- Sessions: 30-minute idle timeout (was 120), encrypted, secure cookies in production. .env.example updated to match so new installations get the safer values.

## [0.46.0] — Sprint H1a-1: authenticator-app sign-in

### Added
- Account → Security: set up an authenticator app (Google or Microsoft Authenticator, Authy, 1Password…) by scanning a QR code (or entering the key); it is switched on only after a working code is confirmed. Ten one-time recovery codes are shown once and stored only as hashes; they can be replaced with a current code.
- Sign-in: staff with an authenticator app enter its code instead of a code by SMS or email (none is sent); a code can never be reused; recovery codes work once each.
- Super admins, owners and practice admins must use an authenticator app: until it is set up, only the security page and sign-out are available, and they cannot turn it off (CLINICFLOW_REQUIRE_AUTHENTICATOR, on by default).
- TOTP implemented to RFC 6238 and verified against its published test vectors; the secret is encrypted and never included when a user record is serialised.

### Notes
- Adds the qrcode npm package (QR codes are drawn in the browser).
- Next (H1a-2): trusted devices, practice-wide requirement, step-up confirmation for sensitive actions, session hardening.

## [0.45.0] — Sprint S1c-2: pagination on long lists

### Changed
- Long lists now load 50 rows per page with page controls (filters kept in the links): the audit log, medical aid claims, pharmacy deliveries, e-scripts received, and the super admin's wallet list. Previously these loaded a fixed 100–200 rows and could not show older records.
- The audit log looks up who performed each action once per page instead of once per row (previously up to 200 extra queries per page).

### Notes
- Patients remain search-first (no browse-everything list), which also limits bulk scrolling through patient records.

## [0.44.0] — Sprint S1c-1: faster loading and Octane readiness

### Changed
- Pages are downloaded only when opened: the main JavaScript file drops from about 1.25 MB to about 364 KB, with each screen in its own small file.
- Practice settings and platform prices are cached (10 minutes, shared through the cache store so every server sees the same values) and cleared the moment they change; each practice's settings are cached separately.
- White-label: the browser tab shows the brand's name instead of "Clinic Flow".

### Added
- Octane configuration (Swoole by default); the super admin's storage choice is re-applied at the start of every request, so a storage switch takes effect without restarting workers.
- N+1 query detection in development and tests: lazy loading is logged as a warning (never thrown) so it can be found and fixed.

## [0.43.0] — Sprint S1b-2: AI scribe in the background

### Changed
- AI scribe recordings are transcribed and drafted in the background (the "ai" queue): consent, length, providers and affordability are still checked at upload, so the doctor hears at once if it cannot be paid for; the doctor's screen and the call page show progress and pick up the draft when ready. On the sync queue (single server, tests) it runs immediately as before.
- Audio is kept encrypted only until the background job picks it up and is deleted before transcription starts. One attempt only, so a retry can never charge twice; billing still happens only after a successful transcription.
- `scribe:purge` also deletes any leftover recording older than an hour.

### Notes
- Lab explanations and chat drafts stay immediate (short text-only calls); report exports remain capped at 5,000 rows.

## [0.42.0] — Sprint S1b-1: background messaging and the queue dashboard

### Added
- SMS and email are sent in the background on a real queue: the message is logged as queued, delivered by a job on the "messages" queue (up to three attempts: after 30 seconds, 2 minutes and 10 minutes), and the log shows sent or failed. Pages no longer wait for the SMS or email supplier. On the sync queue (single server, tests) messages are sent immediately as before.
- Horizon queue dashboard at /admin/horizon on the platform domain only, super admin only; supervisors for messages, AI and default/exports queues with production and local sizes.
- `queue:alert` (hourly): emails platform admins when background jobs failed in the last hour.

## [0.41.0] — Sprint S1a: file storage chosen by the super admin

### Added
- Admin → Storage: keep files on this server's disk (default) or in Amazon S3 (e.g. Cape Town af-south-1) or any S3-compatible storage — Google Cloud Storage, MinIO, Cloudflare R2, Wasabi, DigitalOcean Spaces, Backblaze B2. Keys stored encrypted; private buckets; optional encryption at rest.
- A storage must pass a connection test (write, read back, delete) before it can be activated; one storage is active at a time and applies to every practice, each in its own folder.
- `storage:copy-to-active <id>` copies existing files into a storage (each practice into its own folder), verifying every file by size and SHA-256; re-runnable, never deletes (`--dry-run` to preview).
- All file uploads, documents, lab reports, results, invoices, prescriptions, website images, locum documents and message attachments now use the chosen storage.

### Security
- Local storage folders must be outside the application (never inside public/), so files can never be served directly by the web server.

### Notes
- Adds league/flysystem-aws-s3-v3 (composer.lock generated by CI).
- Use S3 or S3-compatible storage before running more than one app server.

## [0.41.0] — Sprint S1a: shared file storage chosen by the super admin

### Added
- Admin → Storage: storage targets — this server's disk, Amazon S3 (e.g. Cape Town af-south-1) or any S3-compatible service (Google Cloud Storage, MinIO, Cloudflare R2, Wasabi, DigitalOcean Spaces, Backblaze B2). Keys stored encrypted; HTTPS endpoints only; folders cannot escape their root.
- Connection test (write, read back, delete a probe file) required before a target can be activated; one active target for the whole platform; switching is logged.
- Every file — uploads, generated documents and invoices, prescriptions, lab reports, network lab reports, website media, locum documents and message attachments — now goes through one "files" disk that follows the active target. Each practice keeps its own folder (per-practice prefix on S3).
- `storage:copy-to-active {target} [--dry-run]`: copies existing local files into the target, each practice into its own folder, verifying every file by size and SHA-256; re-runnable, never deletes.
- Buckets stay private; files are still served only through Clinic Flow's permission checks; encryption at rest on by default.

### Notes
- Default remains this server's disk (single server). Switch to S3-compatible storage before running more than one server.
- Adds league/flysystem-aws-s3-v3 (installed by CI).

## [0.40.0] — Sprint 18C-2: analytics dashboards

### Added
- Analytics (Standard and Pro, finance access): key figures for the chosen period (this month, last month, last 90 days, or custom) with the change against the previous period — billed, collected, collection rate, owed, debtor days, appointments, no-show rate, doctor utilisation (booked vs rostered time), average wait (check-in to being called), visits, new and returning patients, online consults and lab turnaround; optional branch filter.
- Six-month billed vs collected chart; doctor comparison (visits, appointments, no-shows, billed, utilisation); branch comparison (visits, billed).
- Group dashboard roll-up now also shows each practice's collection rate, no-show rate and average wait — totals only, never patient records.
- Figures use the same definitions as the group dashboard (collected = successful payments less refunds; owed = unpaid balance of non-void invoices).

## [0.39.0] — Sprint 18C-1: report builder

### Added
- Reports: build reports from predefined data sets — visits, appointments, invoices, payments, medical aid claims, lab orders and prescriptions — with a date range (up to two years), up to two groupings (day, week, month, doctor, branch, payer, status and more), totals (counts, money in rands, no-shows, lab turnaround) and filters; table and bar chart.
- Every grouping, filter and total is a fixed, whitelisted expression; user input never becomes SQL. Reports show totals only (no patient names).
- Each data set follows its existing permission (money data sets need finance access).
- Export to CSV (opens in Excel) or PDF; every export is recorded in the audit log.
- Saved and scheduled reports (Clinic Standard and Pro): weekly (Mondays, previous 7 days) or monthly (1st, previous month) emails of the totals, only to staff who still have the data set's permission (`reports:send` daily at 07:00).

## [0.38.0] — Sprint 18B-2: brand senders and partner portal

### Added
- Brand email sender: a "from" address on the brand's own domain, used for that brand's practices only after DNS verification (ownership TXT record at _clinicflow.<domain> and the email supplier's SPF include). Until verified, emails use the Clinic Flow address with the practice's name.
- Brand SMS sender name (3–11 letters/numbers), used only once the super admin confirms it is registered and approved with the SMS supplier.
- Partner portal: resellers linked to a brand see the brand's sign-up link, its practices, sign-ups this month and AI minutes this month, alongside their commission statements.

## [0.37.0] — Sprint 18B-1: white-label brands and theming

### Added
- Admin → Brands: brand name, short name (sign-up link ?brand=…), logo (PNG/JPG/WebP/SVG ≤ 200 KB), main and dark colours, support email and phone, footer text, "Powered by Clinic Flow" (on by default), practice domain (practices at *.partner-domain), linked reseller for commission, active.
- Practices that sign up through a brand link join that brand, get their address under the brand's domain, and credit the brand's reseller (unless another reseller link was used first). The super admin can move any practice to a brand; its address stays the same.
- Branding shown to practices and patients: brand logo and name everywhere the logo appears, brand colours applied to the theme, optional "Powered by Clinic Flow".
- Admin → AI scribe: field for the lab explanation model (default Claude Haiku 4.5).

### Notes
- Clinic Flow still bills practices; tax invoices keep naming the billing entity. The partner must point *.their-domain at Clinic Flow (certificates at deployment).

## [0.36.0] — Sprint 18A-2: plain-language lab explanations for patients

### Added
- Results inbox: "Draft explanation (AI)" writes a short plain-language explanation (Claude Haiku 4.5 by default; super admin sets the model) from the results, usual ranges, flags and the doctor's own comment — only the patient's age band and sex are sent, never their name or ID.
- The doctor edits the draft and releases it as the release note; the patient sees it marked "Explained with AI help, reviewed by Dr …".
- Never for critical results; only when the doctor chooses; part of the AI add-on; counts as one AI minute (included minutes first, then the wallet); nothing charged if drafting fails.

## [0.35.0] — Sprint 18A-1b: AI scribe for in-person, video/audio and chat consults

### Added
- Consultation page: AI scribe panel — the doctor asks, confirms the patient agreed (or records the decline), records, and reviews the draft; "Use draft in note" fills the consultation fields for editing and saving; suggested ICD-10 codes are added one by one. A note shows when the patient declined before.
- Video and audio calls: the doctor asks; the patient agrees or declines on their own screen; both see "AI scribe on"; the doctor's browser records both sides of the call; nothing runs while waiting for consent.
- Chat consults: the doctor asks in the chat, the patient agrees or declines there, then the draft is written from the chat messages (no speech-to-text; counts as one minute).
- `scribe:purge` (daily): transcripts and drafts deleted after 30 days; accepted text stays in the consultation note.

## [0.34.0] — Sprint 18A-1a: AI scribe — providers, billing and drafting

### Added
- Super admin → AI scribe: speech-to-text providers Deepgram Nova-3 Medical and Azure AI Speech (South Africa North, South African English) and the note writer Claude (Sonnet 5.5 by default); keys encrypted; one active provider per kind; provider cost per minute recorded; prices for practices; usage per practice.
- AI scribe add-on (offered on clinic and doctor packages): monthly fee with included minutes (default R499 incl. 300 minutes) and packages can include minutes; extra minutes charged from the practice wallet (default R1.50/min); maximum recording length (default 30 minutes). The add-on fee is billed on the subscription invoice.
- The scribe only runs when the practice has it, the doctor starts it and the patient agrees (declines are recorded). A recording the wallet cannot cover is refused before anything is sent.
- Transcription → billing on the provider-measured length (included minutes first) → the patient's names removed → Claude drafts history, examination, assessment, plan and ICD-10 suggestions (invalid codes dropped). Audio is never stored; transcript and draft are encrypted.
- Nothing is charged when transcription fails or no speech is recognised; re-drafting reuses the transcript at no extra charge.
- Settings → AI scribe for practices: switch the add-on, see this month's minutes.

### Next
- 18A-1b: doctor screens (in person, video/audio calls with the patient's on-screen consent, chat consults) and automatic removal of drafts after 30 days.

## [0.33.0] — Sprint 17D-2b: outgoing lab orders and test-code mapping

### Added
- Settings → API → Lab systems: choose which connected lab system receives new lab orders; new in-house orders are assigned to it automatically.
- Lab systems collect their orders at /api/lab/v1/orders (FHIR Bundle of ServiceRequest + Patient) or /api/lab/v1/orders.hl7 (HL7 ORM^O01) and confirm receipt (POST /api/lab/v1/orders/{id}/received); new API permission lab:orders. A lab system only sees its own orders, with the demographics a lab needs (no ID number).
- Test-code mapping per lab system (its code → your catalogue code), used both ways: incoming results are translated before matching, outgoing orders carry the lab's own codes.

## [0.32.0] — Sprint 17D-2a: incoming results from lab systems

### Added
- Connected lab systems send results to /api/lab/v1/hl7 (HL7 v2 ORU^R01 over HTTPS, answered with an HL7 ACK) or /api/lab/v1/fhir (DiagnosticReport with Observations, answered with an OperationOutcome); new API permission lab:write.
- Matching by order number, then sample barcode. Results are classified exactly like staff-entered results (system flags; the lab can only raise a flag) and marked verified by the accredited lab, so the normal release rules apply (doctor review, auto-release of normal results, critical escalation).
- Original messages kept encrypted; duplicates ignored.
- Unmatched lab results (Lab → Unmatched results): no matching order, preliminary results, unexpected or missing tests wait for staff to match to the right order or reject with a reason. A patient or order is never created automatically.

### Changed
- Result classification shared between staff-entered and lab-system results (no behaviour change for staff entry).

## [0.31.0] — Sprint 17D-1: FHIR R4 read API with per-patient consent

### Added
- FHIR R4 read API at /api/fhir/r4 (application/fhir+json): CapabilityStatement (metadata), Patient (consented patients, and by id), AllergyIntolerance, Condition (ICD-10 coded), MedicationRequest (signed prescriptions, NAPPI coded), Immunization, Observation (released lab results only, LOINC coded where available); OperationOutcome errors.
- New API permission fhir:read, which only the practice owner can grant.
- Per-patient, per-system consent with chosen categories (allergies, problem list, medicines, immunisations, released lab results), optional expiry, withdrawal at any time; patients manage it on "My care" in the portal, staff record it (confirmed) on the patient's care page. Clinical notes are never available.
- Every FHIR read is shown to the patient on "My care".

### Changed
- Shared test helpers moved into tests/Pest.php so any subset of tests can run on its own (locally and in each CI part).

## [0.30.0] — Sprint 17C-2: API write endpoints and signed webhooks

### Added
- API write endpoints: book a free slot, reschedule (new time booked and old one cancelled together; nothing changes if the new time is not free), cancel with a reason — all through the same booking rules as the front desk.
- Patient registration through the API requires the integrator to state the patient's POPIA and treatment consent, how it was obtained (online form, paper form, in person) and when; recorded in the audit log with the key used.
- Webhooks (Settings → API): public https:// addresses only (localhost, private, reserved and internal addresses refused, checked again before every delivery; no redirects followed); events appointment.booked, appointment.cancelled, appointment.checked_in, invoice.paid, patient.registered and a test event; payloads carry IDs and status only.
- Signed deliveries: X-ClinicFlow-Signature t=<time>,v1=<HMAC-SHA256 of "time.body">; retries after 1, 5, 30, 120, 360 and 720 minutes; delivery log; an address that fails 5 deliveries in a row is switched off and the owner is emailed. `webhooks:deliver` runs every minute.

## [0.29.0] — Sprint 17C-1: public API keys and read endpoints

### Added
- Practice API keys (Settings → API, owner and practice admin only): chosen permissions, optional IP allowlist and expiry, shown once and stored only as a hash, last-used time, revoke; recent request log.
- Practice API v1 on each practice's own address (/api/v1): free times per doctor, appointments by date range (no reasons), exact patient lookup by cell or ID number (demographics only; ID numbers matched through their lookup hash), invoices by date range, online consult prices and prepaid packages; OpenAPI 3.1 description at /api/v1/openapi.json.
- API guard: package feature `api` (Clinic Pro), valid key, permission, allowed IP, 60 requests per minute per key (configurable); every request logged.
- No clinical information is available through the API.

## [0.28.0] — Sprint 17B-2: corporate wellness billing and anonymised employer reports

### Added
- Employer invoice per wellness day (CW-YYYY-NNNNN): screened employees × contracted rate (excluding VAT), VAT added only when the practice is VAT registered; one invoice per event; mark paid with a reference; PDF.
- Anonymised employer summary: employees screened and the share in each risk band per check, as a PDF. No names or individual results ever. Any figure based on fewer than 10 people is withheld (the whole report if fewer than 10 were screened). Only blood pressure, glucose, cholesterol, BMI and flu vaccination can appear — sensitive tests such as HIV are never included.
- Send to employer: an emailed link (valid 30 days) to the summary and invoice on the practice's own site.

### Fixed
- Locum shift alerts linked to the practice's site instead of the locum portal on the platform domain.

## [0.27.0] — Sprint 17B-1: corporate wellness (accounts, events, screening)

### Added
- Corporate accounts (company, contact, billing details, VAT number, rate per employee).
- Wellness days: date and time window, location, slot length, people per slot, chosen checks (blood pressure, glucose, cholesterol, BMI, flu vaccine), shareable registration link.
- Public employee registration with the employee's own POPIA and treatment consent; matched to an existing patient by cell number, otherwise registered (SA ID or date of birth); full slots refused; one registration per employee per event; SMS confirmation.
- Screening capture by nurses with BMI calculated and risk flags (blood pressure, glucose, cholesterol, BMI); SMS to the employee when results are ready, asking for a follow-up if any value is flagged.
- Employees see their own wellness results on "My care" in the patient portal; employers never see individual results.

### Notes
- Risk thresholds are DEMO values for the clinical reviewer to confirm. Employer billing and anonymised employer reports follow in 17B-2.

## [0.26.0] — Sprint 17A-2: locum marketplace after the shift

### Added
- Hours: the locum submits start, end and unpaid break once the shift has started; the practice confirms them or adjusts them with a reason.
- Shift invoice: issued on confirmation (LOC-YYYY-NNNNNN), PDF from the locum to the practice with hours, rate and total (VAT added only if the locum is VAT registered); "paid directly by the practice, not through Clinic Flow"; the practice can mark it paid.
- Shift alerts: verified locums in the shift's area (or the invited locum) get an email once per shift; optional SMS, off by default.
- Reminders: the day before a booked shift, to the locum and the practice owner (`locums:remind`, hourly).
- Cancellations: either side cancels a booked shift with a reason before it starts; the roster session is removed and the locum's access ends unless another shift is booked; cancellations within 24 hours are recorded as late; the other side is emailed.
- Private "would book again" per completed shift, visible only to that practice and the super admin; the super admin also sees late cancellations per locum.

## [0.25.0] — Sprint 17A-1: locum marketplace (profiles, verification, shifts, booking)

### Added
- Locum profiles for doctors (HPCSA number, qualifications, languages, areas, preferred rate) with HPCSA registration, indemnity cover and CV uploads (expiry dates required for registration and indemnity).
- Super-admin verification (HPCSA number checked on the HPCSA register); verifying requires current registration and indemnity documents; a changed HPCSA number needs re-verification.
- Practices post shifts (open to all verified locums or offered to one), see applicants, accept one; other applicants are declined.
- On acceptance: locum access to that practice only from 1 hour before to 12 hours after each booked shift (several shifts keep their own windows), plus a roster session for patient bookings; a practice's own permanent doctors are never turned into locums.
- Eligibility checked against the shift date (registration and indemnity must be valid), overlapping bookings blocked.
- Optional booking fee per confirmed shift (default R0), billed once on the practice's next subscription invoice.
- Payment for shifts is between practice and locum (not through Clinic Flow).

## [0.24.0] — Sprint 16B-2: support console and status page

### Added
- Support tickets: practices raise tickets and reply; Clinic Flow support replies and closes them.
- Consented support access: only the practice can allow it (1–72 hours, optionally tied to a ticket) and end it at any time. Support opens the practice through a one-minute sign-in link; the session is read-only (any change is refused), every page viewed is written to the practice's audit log, and it ends as soon as the access expires or is revoked.
- Status page: public page and JSON feed showing component health (web app, messaging, video, payments, Network Hub), incidents with updates and planned maintenance; components are checked every minute unless the super admin sets them by hand.
- `status:check` every minute.

### Notes
- At deployment the public status page is also published separately from the main servers (static copy from `/status.json` plus an external uptime monitor) so it stays up during an outage.

## [0.23.0] — Sprint 16B-1: prepaid packages and reseller programme

### Added
- Prepaid packages: practices define fixed services paid in advance (consultations, procedure or lab codes; never open-ended cover). Selling a package creates an invoice; the package activates when it is paid in full and is valid for three years (Consumer Protection Act s63). Staff use a package against a matching unpaid invoice line, which issues a credit note so revenue and VAT stay correct; each line can be covered once; packages expire daily after three years.
- Reseller programme: the super admin adds resellers (commission % and months, default 20% for 12 months); a reseller's link (?ref=CODE) is remembered for 30 days and the practice that signs up is credited to them; commission is recorded on each paid subscription invoice (excluding VAT) within the window; monthly statements; the super admin marks months paid with the EFT reference; resellers see their link, referrals and statements in a reseller portal.
- `packages:expire` daily.

### Notes
- Legal reviewer to confirm prepaid package wording (not insurance / Medical Schemes Act) and validity.

## [0.22.0] — Sprint 16A: website media, sections, booking widget, patient feedback

### Added
- Website media library: upload JPG/PNG/WebP (5 MB), resized for the web with a thumbnail and camera data removed, alt text required, "used on" tracking (images in use cannot be deleted), share image for social links.
- New page sections: team, gallery, opening hours, directions, book online (next free times) and patient feedback.
- SEO: social-share tags, sitemap.xml and robots.txt for every practice site.
- Embeddable booking widget (one script tag): next free times per doctor from a public, names-and-times-only feed; booking opens on the practice's site.
- Patient feedback: one request after a finished visit (at most every 30 days, opt-out respected), 1–5 stars and comment, practice replies, abuse reporting with super-admin decision. Private by default; shown on the website only after the practice confirms legal approval (HPCSA advertising rules) and only where the patient agreed, with initials.
- `feedback:request` daily.

### Notes
- Servers need the PHP GD extension for image resizing (added to CI).

## [0.21.0] — Sprint 14B: WhatsApp, couriers, pharmacy comparison

### Added
- WhatsApp: super admin chooses the supplier (Meta WhatsApp Cloud API, Twilio or Clickatell — one active), tracks template approvals (synced from Meta) and sets per-message prices (utility, marketing, authentication). Practices switch on the WhatsApp add-on (monthly fee on the subscription). Messages go by WhatsApp only when the patient prefers it and opted in, the template is approved and the practice wallet covers it; otherwise, and whenever WhatsApp fails, by SMS as before. Each WhatsApp message is charged to the practice wallet and logged. Reception records opt-in on the patient's care page.
- Couriers: super admin enables Pargo, The Courier Guy and Skynet; each practice links its own courier account or uses manual courier. Bookings are entered with the courier's tracking number until a courier's API is connected (marked by the super admin after sandbox testing).
- Deliveries: practice rule for who pays (patient, practice, or by order-value threshold, with the payer below and above it); the fee is added to the patient's invoice when they pay; Schedule 5+ medicine must be collected unless the pharmacy confirms it is approved to deliver it; status tracking; delivery confirmed only with the code sent to the patient.
- Pharmacy comparison: pharmacies opt in to publishing stock and prices to the Network Hub (hourly); doctors and patients see which network pharmacies have every item on a script and an estimated total (clearly marked as an estimate).
- `pharmacy:publish-stock` hourly.

## [0.20.0] — Sprint 14A: branches, groups, custom domains, calendars

### Added
- Branches inside a practice (shared patients): package limits plus an extra-branch add-on billed monthly; staff assignment; a branch switcher; new visits, appointments, invoices, cash-ups, roster sessions and received stock are tagged with the branch in use; the front-desk queue and dispensing use the current branch once a practice has more than one; stock per branch and transfers (earliest expiry first, recorded in the S5/S6 register).
- Practice groups: group admins see totals per practice (visits, appointments, new patients, takings, amounts owed) — never patient records; optional combined monthly invoice that settles every practice's subscription invoice.
- Custom domains: add a domain, follow the DNS records shown (CNAME, ownership TXT, email SPF), verify; the practice is then served on its own domain; an endpoint tells the web server which domains may get certificates (on-demand TLS, finished at deployment).
- Calendars: doctors connect Google Calendar or Microsoft 365/Outlook (super admin registers Clinic Flow once); appointments sync as "Appointment" (initials optional, never names or reasons), cancelled ones are removed, busy times can block bookable slots, and every doctor has a private iCal feed.
- `calendar:busy` every 15 minutes.

## [0.19.0] — Sprint 13B: VAT, procurement, debtors and accounting connections

### Added
- VAT: practice setting (registered or not, VAT number, rate, zero-rated line types); VAT portion on every line, tax invoices for registered practices, VAT on credit notes, VAT201 figures (output, input, payable) and VAT fields on the invoice PDF.
- Procurement: suppliers, purchase orders (VAT on VAT-registered suppliers), email to supplier, receiving against the order (batch, expiry, selling price; partial receipts), reorder list, stock takes with reasons (earliest expiry first) and expired-batch write-offs; every S5/S6 movement recorded in the register.
- Debtors: ageing (current, 30, 60, 90+), one monthly statement per patient with a pay link, bad-debt write-off requested by one person and approved by another (issued as a credit note).
- Accounting connections: super admin offers Xero, Sage Business Cloud Accounting and Zoho Books with Clinic Flow's app credentials and connects the platform's own books; each provider connects its own app (one active), maps its accounts and posts balanced daily journals automatically (daily or hourly) or on demand; tokens are encrypted and refreshed.
- Built-in exports for every provider: journal, invoices, payments and VAT as CSV, Excel and PDF.
- `accounting:export` (daily and hourly).

## [0.18.0] — Sprint 13A: referrals, clinician messaging, chronic and preventive care

### Added
- Share-history consent by category (allergies, medicines, problems, results, notes) with optional expiry, given with a code to the patient's phone or in the portal, revocable at any time.
- Referrals to network practices with a clinical summary built only from consented categories; status and feedback flow back; printable referral letters for practices not on the network.
- Clinician messaging about a patient within a practice or, with consent or an active referral, e-script or lab order, across practices; each practice keeps its own copy encrypted with its own key; messages cannot be edited or deleted (corrections are new messages); attachments; filing to the record; "visible to patient"; urgent messages notify by email and escalate to the covering doctor after 4 hours.
- Patient transparency in the portal: who discussed their care and what was shared, referral outcomes, messages marked visible, and their sharing choices.
- Break-glass review: reason, second approval, read-only for 7 days, logged and shown in the patient's log.
- Problem list; chronic scripts renewed as new drafts for PIN signing; chronic monitoring due dates.
- Preventive recalls with opt-out, childhood immunisation schedule and records, pregnancy tracker with risk flags.
- Chronic medicine registration workflow, with a guide to connect it to the switch later.
- `care:tick` for urgent escalation (every 10 minutes) and recalls (daily).

### Notes
- Recall rules, immunisation schedule, antenatal contacts and monitoring rules are DEMO defaults for clinical review.

## [0.17.0] — Sprint 12B: lab templates, network labs and the results release flow

### Added
- Lab test templates per lab: copy tests from the master catalogue (LOINC codes, sample type, result type, plausibility limits, reference ranges by sex and age), edit them, build panels. Reference-range changes need a second person's approval, apply to new results only, and every result keeps the range it was checked against.
- Results entry from templates: technicians type values only; flags (normal/low/high/critical) are computed live from the range for the patient's sex and age; impossible values are refused; choice/text tests need the lab's classification; the lab can raise a flag but never lower it; a PDF without values is "unclassified".
- Network lab requests: the doctor orders from any network lab's own menu (linked patients only), optionally with home collection; the lab accepts or rejects, creates its own record and invoice (tests plus home-collection fee — the lab bills the patient), assigns a collector, collects, enters and verifies; results are delivered into the requesting practice's record and erased from the Hub.
- Patient-requested tests at a lab (no doctor): released straight to the patient after verification.
- Results release flow: doctor releases, releases with a note, or holds values back to discuss in person; patients see status only until release; "Request my results" after 24 hours moves results to the top of the inbox; normal results auto-release after 48 hours with a standard note; abnormal and unclassified results are escalated (never auto-released unless the practice allows abnormal — never critical); critical results not acknowledged within 2 hours are escalated urgently (fixed).
- Covering doctor: a doctor away can name a cover, who sees and acts on their results.
- Patient portal results page: status, notes, values after release, branded results PDF and the lab's own PDF; every view and download is audited.
- `lab:release-tick` every 10 minutes.

## [0.16.0] — Sprint 12A: online consults done properly

### Added
- Online availability per doctor and mode (video, audio, chat or all): weekly hours, date exceptions (days off, partial days off, extra sessions) and a buffer between consults.
- Price table per mode and duration (15 minutes minimum; 30, 45, 60 optional), practice prices with per-doctor overrides; extension price derived from the table.
- Paid booking in the patient portal and at the front desk: doctor → mode → duration → date → times where the whole duration fits; the slot is held while the patient pays (default 10 minutes) through the practice's own gateway; confirmed on payment with a confirmation message; unpaid holds expire and release the slot and wallet reservation.
- Each online consult gets a virtual visit (ticket V001…) with a consultation and invoice, so notes, ICD-10, prescribing, e-scripts, finance reports and refunds work as for in-person visits.
- Join rules: waiting screen with camera/microphone test and countdown before the start (no join pass, nothing charged); join from the start time; late joins only within the booked time; time-left display and 2-minute warning; grace period (default 3 minutes) then the call closes; the patient is never charged for grace.
- Extensions: the doctor adds a block (default 15 minutes) during the call; the patient pays first; time and wallet reservation are added on payment.
- Cancellation policy: free cancellation before the cut-off (default 2 hours); no refund inside it; full refund when the practice cancels or the doctor has not joined 10 minutes after the start (fixed). Refunds go back through the practice's gateway — automatic for Paystack and Yoco, a "refund due" task with deadline and reminders for PayFast, Peach, cash and card.
- Chat consults: a live, time-boxed chat for the booked duration, charged per session only if both took part; a follow-up chat window after every online consult (default 3 days).
- Settings → Online consults (hours, exceptions, prices, rules, refund tasks); doctor "Chats" list; patient "Online consults" page.
- `telemedicine:tick` every minute: expire holds, refund doctor no-shows, close finished consults.

### Changed
- Online consults are no longer booked through in-person rosters; the earlier "join 15 minutes before" rule is replaced by joining at the start time.

## [0.15.0] — Sprint 11: telemedicine and e-scripts

### Added
- Video and audio consults inside Clinic Flow (no redirect): LiveKit Cloud or self-hosted LiveKit, each in test or live mode, configured by the super admin (one active; the other kept as standby; test-connection button). Join passes are signed per room and participant; LiveKit webhooks are signature-checked.
- Telemedicine add-on: offered by the package, switched on by the owner (monthly fee on the subscription invoice); online slots come from telemedicine roster sessions.
- Booking an online consult reserves the expected cost in the wallet (refused below the minimum, rolled back cleanly); cancelling releases it.
- Charging: minutes count only while doctor and patient are both connected; charged once when the call ends (rounded up to whole minutes); calls that never connect release the reservation; the appointment is completed.
- Doctor's online consult list and call screen (join from 15 minutes before); patient call screen in the portal with a data-use notice; audio-only consults; adaptive quality.
- E-scripts through the Network Hub: the doctor sends the current signed version to the network pharmacy the patient chose (patient must be linked); the pharmacy sees the signed content and signature fingerprint, accepts or rejects with a reason, and dispenses once; a newly signed version cancels earlier e-scripts.
- Patient portal "Practices linked to you" with remove (withdraws consent; the practice can no longer send e-scripts).

## [0.14.0] — Sprint 10: Network Hub and telemedicine wallet

### Added
- Network Hub (separate `hub` database, identity only — no clinical data): one cell number = one identity across the network; a person new to the network gets an identity and link at registration; providers who find an existing identity see a masked match only (initials, birth year, last three digits of the cell).
- Linking with patient approval: the practice sends a code to the patient's own phone; the patient reads it to reception; the practice's local record is created (consent to be captured at check-in) and the link and consent are recorded. Five attempts, 10-minute expiry.
- Consent register and revocation: every link records its consent; revoking a practice withdraws its consent.
- Telemedicine wallet per provider (Platform database): top-up packs with bonus credit (VAT added), pay link through the platform gateway or the saved subscription card, auto top-up below the minimum (Paystack/Peach saved cards), append-only statement.
- Wallet rules: online consults can be booked only when the available balance is above the minimum; booking reserves the expected cost; calls are never cut — actual minutes are charged when they end (overdraft recovered from the next top-up); cancelled or failed consults release the reservation; the owner is emailed once when the wallet drops below the minimum.
- Super admin wallet settings: per-minute video and audio prices, per-session chat price, default minimum balance and top-up packs; provider wallets lowest first.
- CI creates the Network Hub database; Hub migrations run on the `hub` connection.

### Added
- Email suppliers Twilio SendGrid and Brevo (HTTP APIs) alongside Amazon SES and SMTP; SMS suppliers unchanged (Clickatell, BulkSMS, SMSPortal, Twilio).

### Changed
- One active messaging supplier per channel: switching a supplier on switches the others in that channel off; their saved keys are kept. A supplier cannot be switched on without all its credentials.

## [0.13.0] — Sprint 8C: SMS and email suppliers, templates and allowances

### Added
- Platform-owned SMS and email suppliers, configured by the super admin only (like payment gateways): Clickatell, BulkSMS, SMSPortal, Twilio, Amazon SES (SMTP) and any SMTP server. Test or live mode, one default per channel, encrypted credentials, "Send test".
- Test mode delivers only to the super admin's test recipients; other messages are recorded as suppressed, so no real patient receives test traffic.
- Message catalogue: staff and patient sign-in codes, signing PIN, lab results ready, booking confirmation and reminder, "you're next", payment link, medicine ready, recalls, and the owner's allowance alert — each with fixed channels and allowed placeholders.
- Default wording by the super admin in English, isiZulu, isiXhosa and Afrikaans; patients get their preferred language, falling back to English.
- Providers set their email from-name and reply-to and may reword messages their package includes; security messages (codes, PINs) can never be changed; unknown placeholders are refused.
- Packages now have separate SMS and email allowances, per-channel overage prices and the message types they include, all editable by the super admin. Usage is counted per channel and billed on the next subscription invoice; owners are emailed at 80% and 100%.
- Marketing messages (recalls) respect opt-outs. Every message is logged with its status.

## [0.12.0] — Sprint 8B: default websites

### Added
- clinicflow.co.za ships with a complete default website: home (banner, provider types, how a visit flows, features, data residency, FAQ, call to action), For clinics, For doctors, Pharmacies & labs, For patients, About and Contact, with menu, footer and draft privacy and terms pages (unpublished until legal approval). Copy is factual — no invented customer numbers or testimonials.
- Original brand illustrations in `public/images/site` (no stock-photo licensing); any section image can be replaced with a site image or an https address.
- Pages are built from editable sections (banner, cards, features, steps, image and list, questions, call to action, contact details, text). The super admin edits clinicflow.co.za in Admin → Website, field by field.
- Every new provider gets a default website on its own address, written for its type (clinic, independent doctor, pharmacy, lab): Home, Services, About and Contact, linking to the patient portal. Text uses {name}, {phone}, {email}, {address} and {hours}, so it stays correct when details change. Call buttons appear once a phone number is entered.
- Providers edit their site in Settings → Website (owner and practice admin): pages, menu labels, publishing (the home page always stays published) and contact details.
- `websites:seed-defaults` adds missing default pages to the platform and existing providers without overwriting edits.
- Section content is cleaned on save: only known fields, plain text, safe links (/, https, tel:, mailto:) and images; rich text sanitised.

### Changed
- A provider's address now opens its public website; the staff workspace moved to `/workspace` (sign-in hand-off goes there).

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
