# 0008. Quality gates on every pull request

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

A healthcare platform cannot rely on manual testing alone, and two squads will work in parallel.

## Decision

Pint (Laravel preset), PHPStan level 8 with Larastan, Pest 5 (unit, feature, tenancy and architecture tests) and Vitest + TypeScript strict mode run on every pull request. Tenancy tests run against real MySQL in CI.

## Consequences

Slower first pull requests; far fewer regressions. No new PHPStan baseline entries without tech-lead approval.
