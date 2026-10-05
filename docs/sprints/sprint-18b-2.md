# Sprint 18B-2 — Brand senders and partner portal

| Story | Acceptance | Status |
|---|---|---|
| Brand email | Own-domain from address; ownership TXT + SPF verified before use; personal domains refused | Done |
| Brand SMS | 3–11 character name; used only when approved | Done |
| Partner portal | Brand link, practices, sign-ups this month, AI minutes, commission | Done |

Verified locally before CI: style, PHPStan level 8, brand tests (5), messaging gateway tests (11).

## Before go-live
- The email supplier must also authenticate each brand domain (DKIM) in its own console.
- SMS sender names must be registered with the SMS supplier/networks.
