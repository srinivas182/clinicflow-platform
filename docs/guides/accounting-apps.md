# Registering Clinic Flow with Xero, Sage and Zoho Books

Providers connect their own books with one click, but first the **super admin** registers Clinic Flow
as an app with each vendor (once). Use the redirect URL shown in **Admin → Accounting**:
`https://<platform-domain>/accounting/callback/<xero|sage|zoho>`.

| App | Where to register | Notes |
|---|---|---|
| Xero | developer.xero.com → My Apps → New app (Web app) | Scopes: offline_access, accounting.transactions, accounting.settings |
| Sage Business Cloud Accounting | developerselfservice.sageone.com → Create app | Journals post to ledger account IDs; map each Clinic Flow account to its Sage ledger account ID |
| Zoho Books | api-console.zoho.com → Server-based application | Choose the data centre (com, eu, in, com.au) to match the client's Zoho region |

Then in **Admin → Accounting**: paste the client ID and secret, tick **Offer to providers**, save.
Providers then see **Connect** in **Settings → Accounting**, map their accounts once, and choose daily or hourly export.

Before go-live, connect each app to a demo/sandbox company and post a test day: journals must balance and land on the expected accounts.
