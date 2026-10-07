# Sprint H1b — Protection against data theft

| Story | Acceptance | Status |
|---|---|---|
| Lockout | 10 failures / 30 min from any IP → 15-minute lock; one email; reset on success | Done |
| Unusual access | 100+ distinct patients/hour → owners alerted once/hour; audited | Done |
| Hard limit | 300 distinct patients/hour → refused | Done |
| Export alert | Every patient data export alerts owners | Done |
| Search limit | 60/minute/person | Done |
| Leaked passwords | Breached passwords refused at sign-up (k-anonymity) | Done |

Verified locally before CI: style, PHPStan level 8, data-protection tests (5), sign-in, authenticator and trusted-device tests (13).
