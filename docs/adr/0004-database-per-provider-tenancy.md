# 0004. Database per provider, plus Platform and Network Hub databases

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

The agreed business requirement is that every clinic, doctor, pharmacy and lab has its own database, hosted in South Africa, with per-provider backup, restore and export. Patients also need one identity across providers.

## Decision

Use stancl/tenancy 3 with database-per-tenant. The **Platform database** holds providers, domains, packages, subscriptions and platform users. Each **provider database** (`cf_provider_<id>`) holds that provider's patients, records and money. The **Network Hub database** holds only cross-provider data: patient identity, consents, the e-script registry, referral routing and the provider directory. Provider databases are created and migrated automatically when a provider is created (queued in production).

## Consequences

Strong isolation and simple per-provider restore. Cross-provider features must go through the Hub with explicit consent. Cache, sessions and queues must be tenant-aware (Redis with cache tags), so the database cache and session drivers are not used.
