# Sprint P1 — Performance

| Story | Acceptance | Status |
|---|---|---|
| Per-request permissions | Loaded once; request-scoped (Octane-safe) | Done |
| Practice lookup cache | 5 min; cleared in platform context on any practice/domain change and bulk updates | Done |
| Branch count cache | Cleared on branch changes | Done |
| Index-friendly dates | 18 hot-path filters; indexes used | Done |
| nginx | gzip, immutable asset caching, keep-alive, file cache | Done |

Verified locally before CI: style, PHPStan level 8; visits and billing, scheduling, online consults, support sessions, consultations and call-next, portal, branches/groups/domains, authenticator, brands and performance tests (incl. cache freshness).
