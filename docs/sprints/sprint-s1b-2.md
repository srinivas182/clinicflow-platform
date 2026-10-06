# Sprint S1b-2 — AI scribe in the background

| Story | Acceptance | Status |
|---|---|---|
| Upload checks | Status, length, providers, affordability checked before anything is stored or queued | Done |
| Background job | "ai" queue; audio encrypted until picked up and deleted before transcription; one attempt | Done |
| Billing | Only after successful transcription; failures charge nothing | Done |
| Screens | Consultation panel and call page wait for the draft with progress | Done |
| Safety net | Leftover audio older than an hour deleted | Done |

Verified locally before CI: style, PHPStan level 8, background scribe tests (3), AI scribe tests (8).
