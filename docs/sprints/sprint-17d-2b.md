# Sprint 17D-2b — Outgoing lab orders and test-code mapping

| Story | Acceptance | Status |
|---|---|---|
| Outgoing lab system | Chosen in Settings → API; new in-house orders assigned automatically | Done |
| Order collection | FHIR ServiceRequest bundle or HL7 ORM^O01; own orders only; no ID number; confirm receipt | Done |
| Code mapping | Per lab system; used for incoming results and outgoing orders | Done |

Verified locally before CI: style, PHPStan level 8, all lab inbound/outbound tests.
