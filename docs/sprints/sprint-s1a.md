# Sprint S1a — File storage chosen by the super admin

| Story | Acceptance | Status |
|---|---|---|
| Storage targets | Local (default), Amazon S3, S3-compatible (GCS, MinIO, R2, Wasabi, DO Spaces, Backblaze); encrypted keys | Done |
| Safety | Connection test before activation; local folders outside the app and public/ | Done |
| Isolation | Each practice in its own folder (prefix) | Done |
| Migration | Verified copy command (size + SHA-256), re-runnable, no deletion | Done |
| Call sites | All file reads/writes use the chosen storage | Done |

Verified locally before CI: style, PHPStan level 8, storage tests (4), website media and locum document tests (11).
The S3 library is installed by CI (not reachable from the build workspace); real S3 connections are verified on staging.
