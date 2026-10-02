# 0006. Reverb for real time and chat; self-hosted LiveKit for video

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

Queues, red triage alerts and chat need real-time updates. Video consults must stay in South Africa and be cheap per minute because they are resold through the provider wallet.

## Decision

Laravel Reverb for websockets and chat (messages stored in the provider database). Self-hosted LiveKit SFU + TURN in AWS Cape Town for video and audio, behind a `VideoProvider` adapter so a managed service (e.g. Agora) can be used for a pilot.

## Consequences

Low marginal cost and data residency. We operate LiveKit ourselves (monitoring, scaling).
