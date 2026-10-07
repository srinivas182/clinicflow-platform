# Sprint L1 — Excel export, safer CSV and remaining pagination

| Story | Acceptance | Status |
|---|---|---|
| Excel export | Valid .xlsx; header bold; money format; text never a formula | Done |
| CSV safety | Formula-leading cells neutralised; numbers untouched | Done |
| Pagination | Referrals, prepaid packages sold, reviews, locum shifts | Done |
| N+1 | Locum applications batched; list pages reviewed (no others found) | Done |

Verified locally before CI: style, PHPStan level 8, TypeScript, build, reports (incl. new export test), locums (8), prepaid and website tests (5).
