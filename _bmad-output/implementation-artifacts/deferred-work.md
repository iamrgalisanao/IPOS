# Deferred Work

## 2026-07-12 — G-082 Task 5 review

- `app/Http/Controllers/Admin/SalesMachineProfileController.php` — define a dedicated authorization policy for `admin_override` before changing the existing offline-sequence override behavior. The current request flag is available to every user with `manage_offline_sales_settings`; tightening it requires a product/role decision outside printer-profile scope.
- `resources/js/Pages/Admin/SalesMachineProfiles/Edit.jsx` — revisit the existing whole-form save disablement when unsynced sales are queued. The backend blocks only sequence prefix/next-value changes, while the UI blocks safe unrelated edits unless the override flag is asserted.

## 2026-07-12 — POS admin capability implementation queue

- Task 2: Manual sync/refresh result UX, including success, failure, stale-config, and offline states.
- Task 3: Approval rules configuration for sensitive POS/admin actions; create a planning lock before implementation.
- Task 4: Cash drawer reason configuration without physical drawer readiness claims.
- Task 5: Advanced operations backlog, beginning with a separately scoped variants/modifiers decision.
