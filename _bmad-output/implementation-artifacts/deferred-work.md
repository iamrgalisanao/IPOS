# Deferred Work

## 2026-07-12 — G-082 Task 5 review

- `app/Http/Controllers/Admin/SalesMachineProfileController.php` — define a dedicated authorization policy for `admin_override` before changing the existing offline-sequence override behavior. The current request flag is available to every user with `manage_offline_sales_settings`; tightening it requires a product/role decision outside printer-profile scope.
- `resources/js/Pages/Admin/SalesMachineProfiles/Edit.jsx` — revisit the existing whole-form save disablement when unsynced sales are queued. The backend blocks only sequence prefix/next-value changes, while the UI blocks safe unrelated edits unless the override flag is asserted.
