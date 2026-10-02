# 0009. Patient payments go to provider-owned merchant accounts

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

The platform earns only from subscriptions and wallet usage. Holding patient money would bring payment-regulation obligations.

## Decision

Each provider connects its own merchant account (e.g. Paystack, PayFast, Peach, Yoco) through a `PaymentGateway` adapter. Platform subscriptions and wallet top-ups use a separate platform gateway.

## Consequences

The platform never holds patient money. Each new gateway is an adapter, not a rewrite.
