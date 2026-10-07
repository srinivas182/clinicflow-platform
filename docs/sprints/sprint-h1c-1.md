# Sprint H1c-1 — Security headers, web-server rules and dependency scanning

| Story | Acceptance | Status |
|---|---|---|
| Headers | CSP with per-request nonce, HSTS (production), nosniff, referrer, frame, permissions policies | Done |
| Payments | Redirect page script carries the nonce | Done |
| Debug guard | Forced off in production; logged | Done |
| security:check | Deployment self-check; fails in production on unsafe settings | Done |
| nginx | Only index.php runs; sensitive file types blocked; tokens off; rate limit | Done |
| Dependency scans | composer audit and npm audit in CI | Done |

Verified locally before CI: style, PHPStan level 8, security header tests (4), sign-in tests (7), npm audit (0 vulnerabilities).
composer audit cannot run in the build workspace (Packagist blocked); it runs in CI.

## Next (H1c-2)
Virus scanning of uploads; HTML sanitiser hardening with attack-pattern tests.
