# Local setup

## With Docker (recommended)

1. `cp .env.example .env` and set `DB_HOST=mysql`, `DB_PASSWORD=secret`, `REDIS_HOST=redis`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`.
2. `docker compose up -d`
3. `docker compose exec app composer install`
4. `docker compose exec app php artisan key:generate`
5. `docker compose exec app php artisan migrate`
6. `npm install && npm run dev`
7. Open http://localhost:8000 (platform home).

## Try a provider workspace

```bash
docker compose exec app php artisan tinker
>>> $p = App\Domains\Platform\Models\Provider::create(['name' => 'Sunrise Medical Centre', 'type' => 'clinic', 'status' => 'trial']);
>>> $p->domains()->create(['domain' => 'sunrise.localhost']);
```

Open http://sunrise.localhost:8000 — the request runs inside Sunrise's own database (`cf_provider_<id>`).

## Running checks

```bash
docker compose exec app composer quality
npm run typecheck && npm test && npm run build
```

Tenancy tests need MySQL; with the default SQLite test database they are skipped.
