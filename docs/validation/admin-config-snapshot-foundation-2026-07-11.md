# Admin Config Snapshot Foundation Validation

Date: 2026-07-11
Status: Implemented / Locally Validated Foundation Slice
Scope: POS bootstrap cache, offline IndexedDB cache, offline sale payloads, offline sync validation

## Summary

The first Admin Config Snapshot Foundation slice is implemented. The POS
bootstrap cache now emits a deterministic branch/register-scoped configuration
snapshot with individual version hashes for the currently implemented POS
configuration surfaces.

This is a foundation slice, not the full admin-configuration product surface.
Back Office remains the master configuration surface, while the POS terminal
stores and submits version metadata for offline audit, sync review, and stale
configuration detection.

## Implemented

- Added `config_snapshot_hash` and `config_snapshot` to the POS bootstrap cache.
- Added version hashes for:
  - `layout_version_hash`
  - `catalog_version_hash`
  - `tax_configuration_version_hash`
  - `discount_rules_version_hash`
  - `payment_methods_version_hash`
  - `terminal_policy_version_hash`
  - `printer_profile_version_hash`
- Persisted snapshot metadata in POS IndexedDB cache.
- Stamped offline cash-capture payloads with the cached snapshot metadata.
- Included snapshot metadata in `/pos/offline-sync` payloads.
- Allowed offline sync request validation to accept optional snapshot fields.
- Extended offline import validation to mark real hash drift as
  `accepted_with_warning` instead of blocking sale intake.
- Normalized terminal policy defaults so runtime default hydration does not
  create false drift warnings.

## Boundaries Preserved

- No terminal-side master configuration UI was added.
- No printer/cash drawer hardware readiness claim was made.
- No local official GCT, Z-read, e-journal, or BIR-certified offline finalization
  was introduced.
- No offline card/e-wallet/bank payment finalization was introduced.
- No migration was required for this slice; snapshot metadata remains in
  bootstrap payloads and offline import raw payloads.

## Validation Evidence

- `php artisan test tests/Feature/POS/OfflineBootstrapCacheTest.php tests/Feature/POS/OfflineSalesAuditPayloadTest.php`
  - 9 passed / 59 assertions
- `node --test tests/Frontend/catalogCache.test.js tests/Frontend/offlineQueueSync.test.js`
  - 19 passed
- PHP syntax checks:
  - `app/Models/DiscountType.php`
  - `app/Services/POS/OfflineReadiness/CacheBootstrapService.php`
  - `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
  - `app/Http/Requests/POS/SyncBatchRequest.php`

## Follow-Up

The next admin-configuration slices should focus on product surfaces and
governance around the snapshot:

- register activation and assigned-terminal snapshot download UX
- admin-visible snapshot version/audit log
- payment method offline policy configuration
- layout-register assignment and stale-layout warning UX
- printer profile schema/admin UX, still without physical readiness claims until
  hardware is available
