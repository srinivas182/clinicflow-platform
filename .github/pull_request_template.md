## What changes

<!-- One or two sentences. Link the sprint story. -->

## Why

## How it was tested

- [ ] Pest / Vitest tests added or updated
- [ ] `composer quality` passes locally (Pint, PHPStan level 8, Pest)
- [ ] `npm run typecheck && npm test` pass locally

## Checklist

- [ ] No secrets, patient data or real provider data in code, fixtures or screenshots
- [ ] Migrations are reversible (`down()` implemented)
- [ ] Tenant data stays in the provider database; only cross-provider data goes to the Hub
- [ ] API changes are versioned under `/api/v1` and documented
- [ ] CHANGELOG.md updated for user-visible changes
