# Walkthrough - Terminal Diagnostics, Device Binding, and Payment Policy Editor

This walkthrough details the completed implementation of the **Terminal Config Heartbeat**, **Register Activation & Config Download UX**, and **Payment Method Offline Policy Editor** features.

---

## 1. Terminal Config Heartbeat

Provides a real-time monitor source of truth of active terminal profiles.

### Changes Made
- **Database Schema & Eloquent Model**:
  - Created the `terminal_config_heartbeats` table via database migration:
    - `sales_machine_profile_id` unique key with constraint cascade-on-delete.
    - `app_version`, `device_id`, `config_snapshot` JSON, `last_snapshot_downloaded_at`, `last_successful_sync_at`, `queue_count` integer, `connection_state`, `reported_at`.
  - Created the [TerminalConfigHeartbeat.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Models/TerminalConfigHeartbeat.php) model.
- **Heartbeat API Ingest Endpoint**:
  - Registered `POST /api/pos/heartbeat` under the protected `pos.` sanctum-auth group in [api.php](file:///Users/teamsolo/Documents/Dev/IPOS/routes/api.php).
  - Implemented [TerminalHeartbeatRequest.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Requests/POS/TerminalHeartbeatRequest.php) for validation.
  - Implemented [TerminalHeartbeatController.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Controllers/POS/TerminalHeartbeatController.php) to update or create heartbeat records using a single-row-per-terminal profile upsert pattern.
- **Drift Dashboard & Frontend Integration**:
  - Updated [TerminalSyncMonitorController.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Controllers/Admin/TerminalSyncMonitorController.php) to load recorded heartbeats.
  - Added a premium **Active Heartbeat Diagnostics** sub-panel in the inspect modal inside [Index.jsx](file:///Users/teamsolo/Documents/Dev/IPOS/resources/js/Pages/Admin/TerminalSyncMonitor/Index.jsx) to display version, local queue counts, device IDs, and sync state.

---

## 2. Register Activation & Config Download UX

Establishes a secure registration flow to bind a physical tablet/device to a `SalesMachineProfile`.

### Changes Made
- **Database Migration**:
  - Created migration `2026_07_11_094920_add_activation_fields_to_sales_machine_profiles_table.php` to add:
    - `activation_token_hash` string, nullable.
    - `activation_token_expires_at` timestamp, nullable.
    - `activated_at` timestamp, nullable.
    - `activated_by` uuid, nullable.
    - `activated_device_id` string, nullable.
    - `activation_status` string (`active` default, with statuses: `pending_activation`, `active`, `suspended`, `revoked`, `expired`).
    - `last_activated_ip` string, nullable.
- **Model Modifications**:
  - Added new fields to `$fillable` and `$casts` in [SalesMachineProfile.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Models/SalesMachineProfile.php).
- **Admin Management API & Web Routes**:
  - Registered `POST /admin/sales-machine-profiles/{salesMachineProfile}/activation-code` in [web.php](file:///Users/teamsolo/Documents/Dev/IPOS/routes/web.php) to generate an 8-character code, hash it using SHA-256, set a 24-hour expiration, set status to `pending_activation`, and redirect back flashing the raw code to the session.
  - Registered `POST /admin/sales-machine-profiles/{salesMachineProfile}/revoke-activation` in [web.php](file:///Users/teamsolo/Documents/Dev/IPOS/routes/web.php) to revoke terminal activation, clear device IDs, and set status to `revoked`.
  - Used the unified [AuditLogger.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Services/AuditLogger.php) service to log code generation and revocation actions.
- **POS Handshake Endpoint**:
  - Registered `POST /api/pos/activate` in [api.php](file:///Users/teamsolo/Documents/Dev/IPOS/routes/api.php) handled by [RegisterActivationController.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Controllers/POS/RegisterActivationController.php).
  - Validates code (verifying presence and expiration) and binds the incoming `device_id`.
  - Sets the status to `active` and returns the newly-activated profile's initial config snapshot, version hashes, heartbeat schedule, and offline policy settings.
- **Middleware Security Guard**:
  - Updated [IdentifyTerminalContext.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Middleware/IdentifyTerminalContext.php):
    1. Blocks request if `activation_status` is not `active` (e.g., if pending, suspended, or revoked).
    2. Blocks request if `X-Device-ID` header does not match the bound `activated_device_id`.

---

## 3. Payment Method Offline Policy Editor

Implements a branch-scoped pivot configuration for offline payment methods with strict backend synchronization rules.

### Changes Made
- **Database Schema & Eloquent Model**:
  - Created the `branch_payment_method_settings` pivot table with:
    - `offline_max_limit_centavos` (unsignedBigInteger, nullable) to support integer centavos for offline limits.
    - `offline_policy_note` (text, nullable) to document the reason why a payment method is allowed or blocked.
    - `allow_offline` (boolean) and `requires_reference` (boolean).
  - Created the [BranchPaymentMethodSetting.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Models/BranchPaymentMethodSetting.php) model.
  - Connected relations in [PaymentMethod.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Models/PaymentMethod.php) and added a resolver `getSettingsForBranch` with default fallbacks (Cash default allowed, others default blocked).
- **Admin Management & Audit Logging**:
  - Created [BranchPaymentSettingsController.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Controllers/Admin/BranchPaymentSettingsController.php) to manage overrides.
  - Enforced `manage_payment_methods` permission check.
  - Validates that non-cash/non-custom methods cannot be configured as offline eligible.
  - Logs detailed change history via `AuditLogger` with old and new values.
- **POS Integration & Version Hashing**:
  - Updated [CacheBootstrapService.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Services/POS/OfflineReadiness/CacheBootstrapService.php) to calculate payment methods hash by branch-resolved settings and append the `payment_methods` list to the bootstrap payload.
  - Updated [POSController.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Http/Controllers/POSController.php) to pass resolved methods to Inertia.
- **Client-Side Enforcements**:
  - Updated [catalogCache.ts](file:///Users/teamsolo/Documents/Dev/IPOS/resources/js/POS/offline/catalogCache.ts) to cache methods in IndexedDB.
  - Updated [SplitPayWizard.jsx](file:///Users/teamsolo/Documents/Dev/IPOS/resources/js/Pages/POS/Components/SplitPayWizard.jsx) and helper to dynamically hide non-offline methods and enforce limit checks and required reference fields.
- **Backend Sync Reconciliation**:
  - Updated [OfflineReconciliationService.php](file:///Users/teamsolo/Documents/Dev/IPOS/app/Services/POS/OfflineSync/OfflineReconciliationService.php) to perform backend policy check `validatePaymentPolicy`.
  - Quarantines violations (offline not allowed, limit exceeded, missing reference, missing hash) under `status = conflict` (`STATUS_CONFLICT`) and records detailed JSON reasons.
  - Accepts stale hashes with warning (`accepted_with_warning`).

---

## Verification Results

### Cleanup Verification

The dirty-directory cleanup was split into focused commits and validated with the following targeted checks:

```bash
php artisan test tests/Feature/POS/LayoutRegisterAssignmentTest.php tests/Feature/POS/TerminalHeartbeatTest.php tests/Feature/POS/OfflineBootstrapCacheTest.php
# 30 passed, 121 assertions

php artisan test tests/Feature/POS/BranchPaymentPolicyTest.php tests/Feature/POS/OfflineBootstrapCacheTest.php
# 18 passed, 78 assertions

php artisan test tests/Feature/POS/TerminalActivationTest.php tests/Feature/POS/TerminalHeartbeatTest.php
# 12 passed, 66 assertions

php artisan test tests/Feature/POS/SyncDiagnosticsTest.php tests/Feature/POS/TerminalHeartbeatTest.php
# 24 passed, 84 assertions

node --test tests/Frontend/catalogCache.test.js tests/Frontend/offlineQueueSync.test.js
# 20 passed

npm run build
# passed
```

The wider POS regression suite should still be run before release packaging or branch promotion.
