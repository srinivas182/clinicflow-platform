# Clinic Flow — Platform

Healthcare network SaaS for South Africa: clinics, independent doctors, pharmacies and labs on one platform, with a patient app across all of them.

This repository holds the **web portal and the REST API** (Laravel 13 + React). The Flutter apps live in `clinicflow-mobile` and use this API.

> Confidential · Built by Mayura Consultancy Services for Sekal, Training Young Minds.

## Stack

| Layer | Choice |
|---|---|
| Back end | Laravel 13, PHP 8.4, Octane |
| Web | React 19 + TypeScript + Inertia.js 3, Tailwind CSS 4, Vite 8 |
| API | REST `/api/v1`, Sanctum tokens |
| Tenancy | stancl/tenancy — one MySQL database per provider |
| Data | MySQL 8.4 (Platform, Hub and provider databases), Redis |
| Real time / video | Laravel Reverb, self-hosted LiveKit |
| Quality | Pint, PHPStan level 8 (Larastan), Pest 5, Vitest |
| Hosting | Docker, AWS af-south-1 (Cape Town) |

Architecture decisions are recorded in [`docs/adr`](docs/adr). Manuals for administrators, practice owners and developers are in [`docs/manuals`](docs/manuals).

## Local setup

Requirements: Docker, or PHP 8.4 + Composer 2 + Node 22 + MySQL 8 + Redis 7.

```bash
cp .env.example .env            # with Docker: DB_HOST=mysql DB_PASSWORD=secret REDIS_HOST=redis
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
npm install && npm run dev
```

Open http://localhost:8000. Full guide: [`docs/runbooks/local-setup.md`](docs/runbooks/local-setup.md).

## Quality checks

```bash
composer quality      # Pint, PHPStan level 8, Pest
npm run typecheck && npm test && npm run build
```

CI runs the same checks on every pull request (`.github/workflows`).

## Working on this repository

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CLAUDE.md`](CLAUDE.md) before your first change. All work goes through pull requests; only the tech lead merges to `main`.
