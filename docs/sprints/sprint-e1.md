# Sprint E1 — Branded error pages

| Story | Acceptance | Status |
|---|---|---|
| Pages | 403, 404, 419, 429, 500, 503; both themes; self-contained | Done |
| In-app | Inertia requests get the in-app error page; JSON stays JSON | Done |
| Safety | No server-error details in production; debug page kept for developers | Done |
| CSP | No inline handlers or javascript: links | Done |

Verified locally before CI: style, PHPStan level 8, TypeScript, build, error page tests (4), sign-in and security header tests (11).
