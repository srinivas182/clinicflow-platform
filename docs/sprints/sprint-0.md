# Sprint 0 — Foundations

**Goal:** a working, tested skeleton both squads can build on from Sprint 1.

## Deliverables

| # | Deliverable | Status |
|---|---|---|
| 1 | Repository, branching and pull-request workflow (`CONTRIBUTING.md`, PR template, CODEOWNERS) | Done |
| 2 | Laravel 13 / PHP 8.4 application with React 19 + TypeScript + Inertia 3 | Done |
| 3 | Database-per-provider tenancy: Provider model, automatic database create/migrate/delete, Platform and Hub connections | Done |
| 4 | REST API v1 conventions and `GET /api/v1/health` | Done |
| 5 | Domain module structure for 17 modules with architecture rules | Done |
| 6 | Design system: tokens, IBM Plex Sans, Button, Badge, Card, KPI, Ticket, TriageDot, provider app shell | Done |
| 7 | Quality gates: Pint, PHPStan level 8, Pest 5, Vitest; CI for back end and front end | Done |
| 8 | Local Docker stack (PHP 8.4 FPM, Nginx, MySQL 8.4, Redis 7, Mailpit) | Done |
| 9 | Architecture decision records 0001–0010 | Done |
| 10 | Staging and production environments in AWS af-south-1 | **Blocked — needs AWS account** |
| 11 | Sprint 1–9 backlog with acceptance criteria | In progress |

## Definition of done

- CI green on the Sprint 0 pull request (Backend and Frontend workflows).
- Tenancy test proves: a new provider gets its own database with migrations applied; two providers cannot see each other's data; deleting a provider drops its database; a provider's domain serves its own workspace.
- Tech lead review and merge to `main`.

## Needed from Sekal / Mayura to finish Sprint 0

- AWS account for af-south-1 (staging and production).
- `clinicflow.co.za` DNS access.
- Choice of medical aid switch, drug database vendor and first payment gateway (commercial onboarding starts now, used in Sprints 3–5).
- Named pilot clinic and clinical, pharmacy and legal reviewers.
