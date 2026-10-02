# Contributing

## Branches

- `main` is always deployable. Nobody pushes to it directly.
- Branch names: `sprint-<n>/<short-topic>`, `fix/<short-topic>`, `chore/<short-topic>`.

## Pull requests

1. Open a pull request into `main` using the template.
2. CI must be green: Backend (Pint, PHPStan level 8, Pest) and Frontend (typecheck, Vitest, build).
3. **Merging:** pull requests are squash-merged into `main` once CI is green. Until other developers join, the tech lead has authorised merging after a green build; when the team grows, only the tech lead merges and branch protection is enabled on the Team plan.
4. Squash-merge with a Conventional Commit title: `feat(visits): call next patient`, `fix(billing): ...`, `chore(ci): ...`, `docs(adr): ...`.

## Code rules

- `declare(strict_types=1);` in every new PHP file. PHPStan level 8 must pass without new baseline entries.
- Controllers are thin: validate → one Action → response. Business rules live in `app/Domains/<Module>/Actions`.
- Provider data stays in the provider database. Cross-provider data goes to the Hub only (ADR 0004).
- Every migration has a working `down()`. Provider migrations live in `database/migrations/tenant`.
- API endpoints live under `/api/v1`, return API resources and are documented.
- Never commit secrets, real patient data or real provider data. Use factories and obviously fake names.

## Keeping CI inside the free allowance

Workflows run only when relevant paths change, cancel superseded runs, cache dependencies and time out after 10–15 minutes. Avoid empty "re-run" commits; use **Re-run jobs** in GitHub instead.
