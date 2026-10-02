# 0005. React + Inertia for the web, REST API v1 for mobile

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

The web portal needs a fast single-page experience without a separate front-end application. The Flutter apps, partners and integrations need a stable, versioned API.

## Decision

Web: React 19 + TypeScript + Inertia.js 3 + Tailwind CSS 4. Mobile and integrations: REST under `/api/v1` with Laravel Sanctum tokens and OpenAPI documentation. Web controllers and API controllers call the same domain Actions, so business rules exist once.

## Consequences

Two thin delivery layers over one set of rules. Breaking API changes require `/api/v2`.
