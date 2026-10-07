# Sprint H1d — Key rotation, data retention and reseller page fix

| Story | Acceptance | Status |
|---|---|---|
| Key rotation | No downtime (previous keys); re-encrypts all databases; found by format; counts each value once; unreadable reported | Done |
| Retention | Operational logs pruned by configurable periods; clinical records untouched; dry run | Done |
| Reseller fix | Platform connection used explicitly | Done |

Verified locally before CI: style, PHPStan level 8, key rotation and retention tests (2).
