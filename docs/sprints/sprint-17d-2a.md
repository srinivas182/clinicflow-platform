# Sprint 17D-2a — Incoming results from lab systems

| Story | Acceptance | Status |
|---|---|---|
| HL7 v2 ORU^R01 | HTTPS, ACK AA/AE, duplicates ignored | Done |
| FHIR DiagnosticReport | Bundle or single, OperationOutcome | Done |
| Matching | Order number, then sample barcode | Done |
| Safety | Same classification (lab can only raise flags); verified → normal release rules | Done |
| Unmatched queue | Preliminary/partial/unexpected/unmatched held; staff match or reject with reason; never auto-create | Done |
| Audit | Original message stored encrypted | Done |

Verified locally before CI: style, PHPStan level 8, new tests, all existing lab tests.

## Next (17D-2b)
Outgoing orders to lab systems (FHIR ServiceRequest / HL7 ORM) and per-lab test-code mapping.
