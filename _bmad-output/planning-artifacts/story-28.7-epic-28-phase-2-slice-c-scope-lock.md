# Story 28.7: Epic 28 Phase 2 Slice C — Offline Sync Validation & Reconciliation Service

**Date**: 2026-05-20  
**Status**: Implemented & Locally Validated  
**Implementation Phase**: Complete  

---

## 1. Goal

Define the precise business rules, constraints, and test matrix for **Epic 28 Phase 2 Slice C — Offline Sync Validation & Reconciliation Service**.

Story 28.6 established the data ledger (tables, models, service skeleton, safe endpoint stub).  
Story 28.7 implements the **validation-only layer** of the reconciliation pipeline: receiving batches, validating them against terminal settings and sequence rules, classifying imports as `pending / duplicate / rejected`, and updating the sync endpoint to return a structured validation result.

This slice deliberately **stops before creating any official Sale record**. No `sales` row is written. No GCT is updated. Official posting remains out of scope until the architecture is reviewed at Slice D (Story 28.8+).

## Validation Evidence

```
./vendor/bin/pest tests/Feature/POS/OfflineSyncValidationTest.php
Result: 19/19 tests passing

./vendor/bin/pest tests/Feature/Admin/ tests/Feature/POS/ tests/Feature/RbacEnforcementTest.php
Result: 292/292 tests passing
```

## Governance Note

Story 28.7 implements the **validation-only intake layer** for offline sync. It does **not** create any official Sale record, update grand cumulative total (GCT), touch Z-read counters, finalize e-journal records, or update provisional terminal journals. `reconciled_sale_id` and `reconciled_at` remain `null` on all imports after sync.

## Boundary Integrity Confirmed

- `reconcileImport()` still throws `BadMethodCallException` — Story 28.8+.
- `finalizeJournal()` still throws `BadMethodCallException` — Story 28.8+.
- TC-28.7-12 explicitly asserts zero Sale rows created after sync.

---

## 2. Story Scope Boundaries

### In Scope:

1. **`receiveImportBatch` implementation** in `OfflineReconciliationService`:
   - Accept terminal profile + raw batch payload array.
   - Verify terminal offline enablement via `OfflineSettingsValidator` (cascading: Tenant → Branch → Terminal → Prefix → Status).
   - Verify prefix ownership: `offline_sequence_prefix` on the profile must match the prefix embedded in each `offline_sequence_number`.
   - Check batch reference idempotency: if `(tenant_id, sales_machine_profile_id, batch_reference)` already exists, return the existing `OfflineSyncBatch` without re-processing.
   - Create `OfflineSyncBatch` record with `status = processing` and `submitted_import_count` set.
   - Delegate each import to `validateImport` and `deduplicateImport`.
   - Finalize batch: set `status = completed` or `failed`, update `processed_count` and `failed_count`, set `sync_completed_at`.

2. **`validateImport` implementation** in `OfflineReconciliationService`:
   - Validate that the `raw_payload` structure has required envelope fields:
     - `offline_sequence_number` (string, non-empty)
     - `submitted_at` (parseable timestamp)
     - `items` (non-empty array)
     - each item has `product_id` (UUID), `quantity` (positive integer), `unit_price` (positive decimal)
   - Validate `offline_sequence_number` prefix matches terminal's registered `offline_sequence_prefix`.
   - Validate `offline_sequence_number` suffix is a positive integer (e.g., `INV-T01-0042` → suffix `42`).
   - If validation fails: mark import `status = rejected`, populate `rejection_reason`, return `false`.
   - If valid: leave import `status = pending`, return `true`.
   - **No tax calculation. No total computation. No sale creation.**

3. **`deduplicateImport` implementation** in `OfflineReconciliationService`:
   - Compute `payload_hash` as `SHA-256` of canonical JSON of `raw_payload` (sorted keys, no whitespace).
   - Query `offline_sales_imports` for existing record with same `(tenant_id, sales_machine_profile_id, payload_hash)` and `status != rejected`.
   - If duplicate found: mark import `status = duplicate`, return `true` (is duplicate).
   - If unique: persist `payload_hash` on the import record, return `false` (not duplicate).

4. **Late sync classification**:
   - After import is validated and deduplicated, check if `submitted_at` is older than a configurable threshold (default 72 hours from `now()`).
   - If late: add a `rejection_reason` note (informational only, does not change import status — the import remains `pending`). This is a flag for admin review, not a rejection rule.

5. **`OfflineSyncController::sync` updated**:
   - Replace the 503 stub with real intake logic:
     - Validate request structure via `SyncBatchRequest` form request.
     - Delegate to `OfflineReconciliationService::receiveImportBatch`.
     - Return a structured `202 Accepted` JSON response:
       ```json
       {
         "batch_id": "<uuid>",
         "batch_reference": "BATCH-001",
         "status": "completed",
         "submitted": 3,
         "processed": 2,
         "failed": 1,
         "imports": [
           { "offline_sequence_number": "INV-T01-0001", "status": "pending" },
           { "offline_sequence_number": "INV-T01-0002", "status": "duplicate" },
           { "offline_sequence_number": "INV-T01-0003", "status": "rejected", "reason": "missing items" }
         ]
       }
       ```
     - If terminal offline not allowed: return `422` with `OFFLINE_NOT_ENABLED` error code.
     - If batch reference already processed (idempotent replay): return `200` with the existing batch result.

6. **`SyncBatchRequest` form request** (`app/Http/Requests/POS/SyncBatchRequest.php`):
   - `batch_reference` (required, string, max 64)
   - `imports` (required, array, min 1, max 500)
   - each `imports.*` item:
     - `offline_sequence_number` (required, string)
     - `submitted_at` (required, date)
     - `items` (required, array, min 1)
     - each `items.*`: `product_id` (required, UUID), `quantity` (required, integer, min 1), `unit_price` (required, numeric, min 0)

### Out of Scope:

- Creating `Sale` records from any import (belongs to Story 28.8+).
- Updating `grand_cumulative_total` or GCT on `SalesMachineProfile`.
- Tax calculation, discount resolution, or total computation.
- Updating `offline_terminal_journals` (provisional journal aggregation belongs to Story 28.8+).
- Official GCT, official Z-read, or official e-journal finalization.
- Frontend offline queue, cart store, IndexedDB, or PWA logic.
- Any BIR-approved, BIR-certified, or compliance-claimed labeling.
- Sequence gap detection or recovery triggering (belongs to admin tooling, later slice).

---

## 3. Core Business & Safety Rules

### A. Batch idempotency is required before any processing

If `(tenant_id, sales_machine_profile_id, batch_reference)` already exists:
- Do not create a duplicate batch record.
- Return the original batch result without re-processing any imports.
- Response status: `200` (not `202`) to signal replay detection to the client.

### B. Import rejection must preserve audit record

A rejected import is **not deleted**. It is saved to `offline_sales_imports` with:
- `status = rejected`
- `rejection_reason` populated

This ensures the full submission history is auditable.

### C. Duplicate imports are preserved, not overwritten

A duplicate import is saved with `status = duplicate`. The original pending import is not modified. Both rows coexist.

### D. Prefix ownership is strictly enforced

An import whose `offline_sequence_number` prefix does not match the submitting terminal's `offline_sequence_prefix` is rejected immediately. Cross-terminal sequence mixing is never allowed.

### E. Official records must not be touched

`reconciled_sale_id` and `reconciled_at` on `OfflineSalesImport` must remain `null` at the end of this story. No `Sale` row is written. This is a hard boundary.

---

## 4. New Files

```
app/Http/Requests/POS/SyncBatchRequest.php

app/Services/POS/OfflineSync/OfflineReconciliationService.php   (implementation replaces skeleton)

app/Http/Controllers/POS/OfflineSyncController.php              (sync method replaced with real logic)
```

---

## 5. Configurable Threshold

Add to `config/offline.php` (new file):

```php
return [
    'late_sync_threshold_hours' => env('OFFLINE_LATE_SYNC_THRESHOLD_HOURS', 72),
];
```

---

## 6. Test Matrix

| Test ID | Level | Domain | Scenario | Expected Outcome |
| :--- | :--- | :--- | :--- | :--- |
| **TC-28.7-01** | Integration | Batch intake | Valid batch with 3 valid imports submitted | Batch saved `completed`; 3 imports `pending`; 202 returned. |
| **TC-28.7-02** | Integration | Idempotency | Same batch_reference submitted twice | Second call returns 200 with original batch result; no duplicate batch or imports created. |
| **TC-28.7-03** | Integration | Enablement | Terminal offline_sales_enabled = false | Endpoint returns 422 `OFFLINE_NOT_ENABLED`; no batch or import created. |
| **TC-28.7-04** | Integration | Prefix | Import has wrong prefix for terminal | That import saved as `rejected` with `rejection_reason`; rest of batch proceeds. |
| **TC-28.7-05** | Integration | Deduplication | Two imports with identical payload submitted | Second import saved as `duplicate`; first remains `pending`; both rows exist. |
| **TC-28.7-06** | Integration | Validation | Import missing `items` field | Import saved as `rejected` with validation `rejection_reason`. |
| **TC-28.7-07** | Integration | Validation | Import with `unit_price = 0` | Import saved as `rejected` — zero price is an invalid envelope. |
| **TC-28.7-08** | Integration | Late sync | Import with `submitted_at` > 72h ago | Import saved as `pending` with late-sync note; not rejected. |
| **TC-28.7-09** | Unit | Service | `reconcileImport` still throws `BadMethodCallException` | Official posting not yet implemented — guard must remain. |
| **TC-28.7-10** | Unit | Service | `finalizeJournal` still throws `BadMethodCallException` | Journal finalization not yet implemented — guard must remain. |
| **TC-28.7-11** | Integration | RBAC | Cashier submits valid batch | 202; cashier has `create_sale` permission so sync is allowed. |
| **TC-28.7-12** | Integration | Safety | After sync, `reconciled_sale_id` is null on all imports | No official sale created. Hard boundary confirmed. |
| **TC-28.7-13** | Integration | Request validation | `batch_reference` missing from request | 422 with `batch_reference` validation error. |
| **TC-28.7-14** | Integration | Request validation | `imports` array is empty | 422 with `imports` validation error. |
