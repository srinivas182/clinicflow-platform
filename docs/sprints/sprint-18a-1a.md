# Sprint 18A-1a — AI scribe: providers, billing and drafting

| Story | Acceptance | Status |
|---|---|---|
| Providers | Deepgram Nova-3 Medical, Azure (SA North), Claude; encrypted keys; one active per kind | Done |
| Pricing | Add-on fee + included minutes; package minutes; per-minute wallet overage; max length | Done |
| Consent | Doctor starts; patient agrees or declines (recorded) | Done |
| Cost safety | Refused before sending if unaffordable; failures and empty transcripts not charged; redraft free | Done |
| Privacy | Audio never stored; names removed before Claude; transcript/draft encrypted | Done |
| Draft | History, examination, assessment, plan, valid ICD-10 suggestions only | Done |

Verified locally before CI: style, PHPStan level 8, AI scribe tests.

## Before go-live
- Legal reviewer: POPIA s72 (note text processed outside South Africa), consent wording, provider data-processing agreements.
- Clinical reviewer: draft-note prompt and review workflow.
