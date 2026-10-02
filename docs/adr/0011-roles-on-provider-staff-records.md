# 0011. Roles live on a provider-side Staff record

- Status: Accepted
- Date: 2026-10-03
- Deciders: Mayura Consultancy Services (tech lead)

## Context

User accounts are stored once in the Platform database so one person can work at several providers. Roles and permissions must be stored per provider (ADR 0004), and each provider can adjust its own role templates. Attaching Spatie roles directly to the central `User` model made pivot writes go to the Platform database.

## Decision

Each provider database has a `staff` table whose `id` equals the Platform user id. Spatie `HasRoles` is on `Staff`, not `User`. A `Gate::before` hook answers catalogue permissions (`patients.view`, `scripts.sign`, …) from the current provider's `Staff` record and denies them outside a workspace.

## Consequences

Roles never leak between providers. Each provider can keep provider-specific staff details (professional numbers, rooms) on `Staff`. Adding someone to a workspace creates both the Platform membership and the provider `Staff` record (`AddStaffMember`).
