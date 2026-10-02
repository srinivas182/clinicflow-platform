# 0007. AWS af-south-1, Docker, blue-green deploys

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

POPIA cross-border rules and the agreed decision require patient data to stay in South Africa. Updates must not interrupt clinics.

## Decision

Host everything in AWS af-south-1 (Cape Town). Ship Docker images built by CI. Deploy blue-green with health checks; provider database migrations run per provider after automatic backup, with rollback on failure.

## Consequences

Data residency is guaranteed. AWS Cape Town egress pricing is higher than other regions and must be watched for video.
