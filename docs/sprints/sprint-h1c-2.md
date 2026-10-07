# Sprint H1c-2 — Allowlist HTML sanitiser and upload virus scanning

| Story | Acceptance | Status |
|---|---|---|
| Sanitiser | Allowlist on HTML5 parser; 16 attack patterns blocked; safe links kept; list blocks intact in tables | Done |
| Virus scanning | Every upload checked (ClamAV INSTREAM); infected refused and audited; fail-closed by default | Done |
| Deployment | clamav service in docker-compose; security:check flags scanning off | Done |

Verified locally before CI: style, PHPStan level 8, sanitiser tests (18), upload scanning (3), security headers (4), website/CMS/document tests (18).
