# Connecting chronic medicine registration to the claims switch

Sprint 13A records chronic medicine registrations as a workflow: **draft → submitted → approved / declined**.
Until a switching house is connected, the practice submits the application through the scheme's own channel
(portal, email or fax) and records the outcome and reference in Clinic Flow.

## When the client chooses a switching house
1. Obtain the switch's chronic-registration specification and test credentials.
2. Extend the `ClaimsSwitch` contract with `submitChronicRegistration()` and `chronicRegistrationStatus()`.
3. Implement them in the chosen switch adapter (alongside eligibility and claims), with a sandbox test.
4. In `ChronicRegistration::submit()`, call the switch and store its reference; poll or receive the decision and call `decide()`.
5. Keep the manual path as a fallback for schemes the switch does not support.

No change is needed to the screens: the same statuses and references are shown.
