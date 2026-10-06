# Sprint H1a-1 — Authenticator-app sign-in

| Story | Acceptance | Status |
|---|---|---|
| Set-up | QR code or key; on only after a confirmed code; secret encrypted and hidden | Done |
| Recovery | 10 one-time codes, shown once, hashed; replaceable with a current code | Done |
| Sign-in | App code replaces SMS/email code; no reuse; recovery codes once each | Done |
| Requirement | Super admins, owners, practice admins must set it up and cannot turn it off | Done |
| Standard | RFC 6238 test vectors pass | Done |

Verified locally before CI: style, PHPStan level 8, TypeScript, build, front-end tests, authenticator tests (3), existing sign-in tests (7).
