# Sprint D0 — cPanel hosting compatibility

| Story | Acceptance | Status |
|---|---|---|
| Practice databases | Created and granted via cPanel UAPI; clear errors | Done |
| Front end without Node | deploy branch = main + public/build, built on every merge | Done |
| One-command update | deploy.sh (pull, install, migrate all, caches, security check) | Done |
| LiteSpeed rules | .htaccess mirrors nginx protections; caching; compression | Done |
| Compatibility | Manual workflow: MariaDB 10.11 + PHP 8.4, MySQL 8.4 + PHP 8.5 | Done |

Verified locally before CI: style, PHPStan level 8, cPanel adapter tests (3), workflow YAML and status-function rule.
