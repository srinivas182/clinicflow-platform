# Deploying on cPanel hosting (Afrihost)

Suited to a pilot or staging site. National production needs the infrastructure in the readiness assessment.

| Item | Value |
|---|---|
| App folder | `/home/drbusinessflow/clinicflow` (outside public_html) |
| Document root | `/home/drbusinessflow/clinicflow/public` |
| PHP | 8.4 (`/opt/cpanel/ea-php84/root/usr/bin/php`) |
| Database server | MariaDB 10.11 (`DB_CONNECTION=mysql`) |
| Code updates | `bash deploy.sh` (pulls the `deploy` branch: code + built front end) |
| Settings template | `.env.cpanel.example` |

## First super admin

`php artisan clinicflow:create-admin` — asks for name, email, mobile and a password (typed hidden), then sets up the authenticator app so the first sign-in needs no SMS or email. Never run the demo seeder on a live site; `php artisan db:seed --force` is safe (production loads packages and website pages only).

## Two-step sign-in

Off by default (good for a demo). Admin → Security switches it on and sets the method order (authenticator app, email, SMS). Switch it on before real patient data; `security:check` reminds you while it is off.

## Demo data

`php artisan clinicflow:demo` (no questions) creates a demo super admin plus a demo clinic, pharmacy and lab (free packages, every role, sample patients) and prints the accounts and one password; `--remove` deletes them all. Sign-in codes go by email until an SMS supplier is added (cPanel mailbox as an SMTP provider in Admin → Messaging).

## Bot protection

Admin → Security → Bot protection: add the Cloudflare Turnstile site key and secret key (dash.cloudflare.com → Turnstile → Add site, domain drbusinessflow.com) and switch it on. It covers sign-in, sign-up, forgot password and patient portal sign-in on the main site and every practice site. Cloudflare's test keys (site 1x00000000000000000000AA, secret 1x0000000000000000000000000000000AA) always pass, for trying it out.

Optional, later: put the domain behind Cloudflare's proxy (DNS) for DDoS and attack filtering — plan the SSL change for the wildcard first.

## Passwords

People change their own password in Account → Security, or use "Forgot your password?" on the sign-in page (needs an email supplier). On the server: `php artisan clinicflow:set-password <email>`.

## Product name

Set `APP_NAME="Dr Business Flow"` in `.env` (used as the email sender name and in page titles), then `php artisan optimize`.

## Cron jobs (cPanel → Cron Jobs)

| When | Command |
|---|---|
| Every minute | `/opt/cpanel/ea-php84/root/usr/bin/php /home/drbusinessflow/clinicflow/artisan schedule:run >/dev/null 2>&1` |
| Every minute | `/opt/cpanel/ea-php84/root/usr/bin/php /home/drbusinessflow/clinicflow/artisan queue:work --stop-when-empty --max-time=55 >/dev/null 2>&1` |

## Practice databases
Created through cPanel's API (`TENANCY_DB_MANAGER=cpanel`). Create an API token in cPanel → Manage API Tokens and set `CPANEL_API_TOKEN`; names start with `TENANT_DB_PREFIX` (`drbusinessflow_cf_`).

## Database engine
The app always creates InnoDB tables (`DB_ENGINE`, default InnoDB); some cPanel MariaDB servers default to MyISAM, which has no transactions or foreign keys.

## Limits on shared hosting
No Redis, queue workers, real-time server or virus scanner: cache, sessions and queues use the database, background jobs run by cron each minute, screens refresh by polling, and upload scanning stays off.
