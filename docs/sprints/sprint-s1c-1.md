# Sprint S1c-1 — Faster loading and Octane readiness

| Story | Acceptance | Status |
|---|---|---|
| Lazy pages | Each page loaded when opened; main bundle ~1.25 MB → ~364 KB | Done |
| Settings cache | Practice settings and platform prices cached; cleared on change; per practice | Done |
| Brand title | Browser tab shows the brand's name | Done |
| Octane | Config; storage re-applied per request (switch without restart) | Done |
| N+1 visibility | Logged in development and tests, never thrown | Done |

Verified locally before CI: style, PHPStan level 8, TypeScript, front-end tests (5), speed/readiness tests (4).

## Next (S1c-2)
Pagination with server-side search on large lists; fix N+1 queries found by the new logging.
