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

## Demo data

`php artisan clinicflow:demo --email=you@example.com` creates a demo clinic, pharmacy and lab (free packages, every role, sample patients); `--remove` deletes them. Sign-in codes go by email until an SMS supplier is added (cPanel mailbox as an SMTP provider in Admin → Messaging).

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
