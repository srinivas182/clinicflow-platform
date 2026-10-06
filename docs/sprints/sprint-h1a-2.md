# Sprint H1a-2 — Step-up confirmation and session hardening

| Story | Acceptance | Status |
|---|---|---|
| Step-up | 9 sensitive routes; password or authenticator code; 15 minutes; JSON 423 for script downloads | Done |
| Sign-in history | Last 10 shown; new-device email | Done |
| Sign out other devices | Password required; other sessions end on next request | Done |
| Sessions | 30-minute idle, encrypted, secure cookies in production; .env.example updated | Done |

Verified locally before CI: style, PHPStan level 8, TypeScript, build, session security tests (4), support and reports tests (9).

## Next (H1a-3)
Trusted devices (skip the code for 30 days), practice-wide authenticator requirement.
