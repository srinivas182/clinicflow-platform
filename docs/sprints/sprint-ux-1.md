# Sprint UX-1 — Dark mode and accessibility basics

| Story | Acceptance | Status |
|---|---|---|
| Tokens | Hard-coded colours replaced (131 in 69 files); chrome tokens for sidebars/display | Done |
| Dark mode | Device setting by default; System/Light/Dark switch; no flash (nonce) | Done |
| Contrast | All key text pairs ≥ 4.5:1 in both themes | Done |
| Keyboard | Focus outline everywhere; skip link in staff and admin layouts | Done |
| Motion | Reduced motion respected | Done |
| Labels | Icon buttons all labelled (scan: 0 missing); images have alt; 5 inputs labelled | Done |

Verified locally before CI: TypeScript, build (tokens and dark overrides present), front-end tests (7, incl. theme switch).

## Next (UX-2)
Screen-by-screen review in both themes; form error announcements; table headers and captions; mobile layouts.
