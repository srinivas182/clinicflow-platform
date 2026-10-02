# Network Hub migrations

Migrations for the `hub` connection: patient identity, consent register,
e-script registry, referral routing and provider directory.

No clinical records are stored here. Tables arrive from Sprint 10 onwards.

Run with: `php artisan migrate --database=hub --path=database/migrations/hub`
