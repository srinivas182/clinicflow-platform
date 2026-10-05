# Sprint 17D-1 — FHIR R4 read API with per-patient consent

| Story | Acceptance | Status |
|---|---|---|
| FHIR resources | Patient, AllergyIntolerance, Condition, MedicationRequest, Immunization, Observation (released only); CapabilityStatement | Done |
| Access | fhir:read permission, owner-only; per-patient per-system consent; categories; expiry; withdrawal | Done |
| Consent management | Portal (patient) and care page (staff, confirmed) | Done |
| Transparency | Every read logged on the patient's "My care" | Done |
| Notes | Clinical notes never exposed | Done |

Verified locally before CI: style, PHPStan level 8, new and affected tests.
