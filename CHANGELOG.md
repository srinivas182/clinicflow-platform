# Changelog

All notable changes to Clinic Flow are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] — Sprint 0 foundations

### Added
- Laravel 13 / PHP 8.4 application skeleton with React 19 + TypeScript + Inertia.js 3 front end.
- Database-per-provider tenancy (stancl/tenancy 3): provider model, automatic database creation, migration and deletion; Platform and Network Hub connections.
- REST API v1 with `GET /api/v1/health`.
- Clinic Flow design system: colour tokens, IBM Plex Sans, Button, Badge, Card, KPI, queue Ticket and triage components.
- Domain module structure for all 17 business domains.
- Quality gates: Pint, PHPStan level 8 (Larastan), Pest 5 (feature, unit, tenancy and architecture tests), Vitest.
- CI workflows for back end and front end with path filters, caching and cancellation.
- Local Docker stack: PHP 8.4 FPM, Nginx, MySQL 8.4, Redis 7, Mailpit.
- Architecture decision records 0001–0010, contribution guide, security policy.
