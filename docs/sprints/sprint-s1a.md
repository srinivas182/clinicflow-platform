# Sprint S1a — Shared file storage

| Story | Acceptance | Status |
|---|---|---|
| Targets | Local, Amazon S3, S3-compatible providers; encrypted keys; https only; safe folders | Done |
| Test and activate | Probe write/read/delete required; one active target; audited | Done |
| One disk | All file call sites use the "files" disk; per-practice folders | Done |
| Migration | Verified copy (size + SHA-256), re-runnable, no deletes | Done |

Verified locally before CI: style, PHPStan level 8, storage tests (9), document/media/prescribing/locum tests (28).

## Deployment checklist
1. Create a private bucket (e.g. AWS af-south-1) with encryption and versioning on; an IAM user limited to that bucket.
2. Admin → Storage: add, test.
3. `php artisan storage:copy-to-active <id> --dry-run`, then without --dry-run.
4. Activate; run the copy once more.
