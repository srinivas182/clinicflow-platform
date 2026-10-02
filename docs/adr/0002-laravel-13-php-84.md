# 0002. Laravel 13 on PHP 8.4

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

Laravel 11 reached end of security support in March 2026 and Laravel no longer publishes LTS releases. Laravel 13 (March 2026) is the current major, with bug fixes until Q3 2027 and security fixes until Q1 2028. Pest 5 and spatie/laravel-activitylog 5 require PHP 8.4.

## Decision

Use Laravel 13 and PHP 8.4. Upgrade to each new Laravel major within six months of release, as a planned sprint item, delivered to all providers through the update engine.

## Consequences

We stay on supported versions. Upgrades become routine work rather than emergencies.
