# 0003. Modular monolith with domain modules

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

The platform covers 17 business domains. Microservices would multiply deployment, monitoring and data-consistency work for a team of 14; a single unstructured app would decay quickly.

## Decision

One Laravel application with domain modules under `app/Domains/<Module>`. Modules call each other through Actions or events, not through each other's tables. Architecture tests enforce the rules that can be automated.

## Consequences

Simple deployment and transactions. A module can be extracted into a service later if load or team size demands it.
