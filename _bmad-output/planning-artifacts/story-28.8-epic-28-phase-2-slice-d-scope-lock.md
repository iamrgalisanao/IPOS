# Story 28.8: Epic 28 Phase 2 Slice D — Offline Import Server Recalculation

**Date**: 2026-05-20  
Status: ACCEPTED WITH GOVERNANCE NOTES
Story: 28.8 — Epic 28 Phase 2 Slice D
Scope: Offline Import Server Recalculation
Result: Implemented & Locally Validated

---

## 1. Goal

Define the precise business rules, constraints, and test matrix for **Epic 28 Phase 2 Slice D — Offline Import Server Recalculation**.

Story 28.7 validated the **structural envelope** of each offline import (prefix, sequence format, items shape).  
Story 28.8 validates the **economic content**: resolves each item against the live server product catalogue, runs the server-side tax engine (reusing `SaleCreationService`'s calculation logic), computes expected totals, compares against client-submitted totals, and marks each import `server_verified` or `conflict`.

This slice deliberately **stops before creating any official Sale record**. Imports exit this slice with one of:
- `server_verified` — server total matches client total within tolerance
- `conflict` — server total differs from client total beyond tolerance
- `rejected` — product missing, inactive, or otherwise unresolvable

**Official Sale posting, GCT update, Z-read, and e-journal finalization remain out of scope until Story 28.9+.**

---

## 2. New Status on OfflineSalesImport

Add two new lifecycle statuses to `OfflineSalesImport`:

| Status | Meaning |
| :--- | :--- |
| `server_verified` | Server recalculation succeeded; totals match within tolerance. |
| `conflict` | Server total differs from client-submitted total beyond tolerance threshold. |

These join the existing statuses: `pending`, `duplicate`, `rejected`, `validated`, `posted`.

---

## 3. New Column on `offline_sales_imports`

Add via migration:

```sql
ALTER TABLE offline_sales_imports
  ADD COLUMN server_recalculation  jsonb   NULL,
  ADD COLUMN conflict_notes        text    NULL;
```

`server_recalculation` stores the full server-computed line-by-line tax breakdown as a JSONB snapshot (same structure as the existing `tax_profile_snapshot` on `Sale`).  
`conflict_notes` stores a human-readable summary of the discrepancy (e.g. `client_total=1200.00 server_total=1092.86 difference=107.14`).

---

## 4. Story Scope Boundaries

### In Scope:

1. **Migration**: Add `server_recalculation` (jsonb, nullable) and `conflict_notes` (text, nullable) to `offline_sales_imports`.

2. **`OfflineSalesImport` model**: Add `server_recalculation` (cast `array`) and `conflict_notes` to `$fillable` and `$casts`. Add `STATUS_SERVER_VERIFIED` and `STATUS_CONFLICT` constants.

3. **`OfflineImportRecalculationService`** (new class, `app/Services/POS/OfflineSync/OfflineImportRecalculationService.php`):

   ```php
   public function recalculate(OfflineSalesImport $import): array
   ```

   Internal steps:
   - Extract `raw_payload['items']` array.
   - Resolve each `product_id` from the server's `products` table using `Product::active()->with('taxCategory')`. 
   - If any product is missing or inactive: mark import `status = rejected`, `rejection_reason = 'product_not_found:{ids}'`, return `['status' => 'rejected', 'reason' => '...']`.
   - Compute server-side totals using the same tax calculation logic as `SaleCreationService` (lines 99–215 of `SaleCreationService.php`):
     - `vatable`, `exempt`, `zero_rated`, `non_vat` buckets
     - inclusive VAT deduction: `net = gross / (1 + rate/100)`
     - per-item `tax_amount`, `net_amount`, `vatable_amount`, etc.
   - Extract client-submitted totals from `raw_payload`:
     - `client_subtotal`, `client_tax_total`, `client_total` (all required as numeric fields in the payload)
   - Compare server totals vs client totals using a configurable tolerance (default `0.01` PHP):
     - If `abs(server_total - client_total) <= tolerance` AND `abs(server_tax_total - client_tax_total) <= tolerance`: **match**.
     - Otherwise: **conflict**.
   - On match: update import `status = server_verified`, persist `server_recalculation` snapshot.
   - On conflict: update import `status = conflict`, persist `server_recalculation` snapshot and `conflict_notes`.
   - Return structured result array.

4. **`receiveImportBatch` updated** in `OfflineReconciliationService`:
   - After `deduplicateImport` (and only for non-duplicate, non-rejected imports), call `OfflineImportRecalculationService::recalculate`.
   - Update `processed_count` / `failed_count` based on `server_verified` vs `conflict` / `rejected`.

5. **Endpoint response updated** in `OfflineSyncController::sync`:
   - Extend per-import status in the response to include `server_verified`, `conflict`, `conflict_notes`.
   - No other response shape changes.

6. **Config**: Add to `config/offline.php`:
   ```php
   'recalculation_tolerance' => env('OFFLINE_RECALCULATION_TOLERANCE', 0.01),
   ```

7. **`SyncBatchRequest` updated**: Require `client_subtotal`, `client_tax_total`, `client_total` within each import:
   ```php
   'imports.*.client_subtotal'  => ['required', 'numeric', 'min:0'],
   'imports.*.client_tax_total' => ['required', 'numeric', 'min:0'],
   'imports.*.client_total'     => ['required', 'numeric', 'min:0'],
   ```

### Out of Scope:

- Creating `Sale` records from any import (Story 28.9+).
- Deducting inventory or calling `FefoAllocationService`.
- Creating payment records.
- Updating `grand_cumulative_total` (GCT) on `SalesMachineProfile`.
- Updating `offline_terminal_journals`.
- Official Z-read, official e-journal finalization.
- Statutory discount processing (not present in client payload yet).
- Frontend offline queue, cart store, IndexedDB, or PWA logic.
- Branch product pricing overrides (`BranchProductPricing`) — use base `Product.selling_price` only for now.

---

## 5. Core Calculation Rules

### A. Tax computation follows BIR inclusive VAT rules

Reuse the exact same tax calculation algorithm as `SaleCreationService`:

```php
// Vatable (inclusive VAT):
$net  = $gross / (1 + $rate / 100);
$tax  = $gross - $net;

// Exempt, Zero-rated, Non-VAT:
$net  = $gross;
$tax  = 0;
```

`tax_type` comes from `Product->taxCategory->tax_type`, normalized to lowercase.  
Valid values: `vatable` / `vat`, `exempt` / `exm`, `zero-rated` / `zero_rated` / `zro`, else `non-vat` (default).

### B. Tolerance comparison

```php
$tolerance    = config('offline.recalculation_tolerance', 0.01);
$totalDiff    = abs($serverTotal    - $clientTotal);
$taxDiff      = abs($serverTaxTotal - $clientTaxTotal);
$isMatch      = $totalDiff <= $tolerance && $taxDiff <= $tolerance;
```

A tolerance of `0.01` allows for rounding differences introduced by the offline client's floating-point arithmetic.

### C. Conflict is not rejection

A `conflict` import is **not** rejected. It is saved with full server recalculation snapshot for admin review. It will require explicit admin override or re-verification before posting in Story 28.9+.

### D. Official sale boundary is absolute

`reconciled_sale_id` and `reconciled_at` must remain `null` after this story. No `Sale` row is created.

---

## 6. New Files

```
database/migrations/{timestamp}_add_server_recalculation_to_offline_sales_imports.php

app/Services/POS/OfflineSync/OfflineImportRecalculationService.php
```

### Modified Files

```
app/Models/OfflineSalesImport.php              (new constants, fillable, casts)
app/Services/POS/OfflineSync/OfflineReconciliationService.php  (call recalculate after dedup)
app/Http/Requests/POS/SyncBatchRequest.php    (add client total fields)
app/Http/Controllers/POS/OfflineSyncController.php  (extend import result shape)
config/offline.php                             (add recalculation_tolerance)
```

---

## 7. Test Matrix

| Test ID | Level | Domain | Scenario | Expected Outcome |
| :--- | :--- | :--- | :--- | :--- |
| **TC-28.8-01** | Integration | Recalculation | Valid batch, client totals match server computation | Imports marked `server_verified`; 202; `server_recalculation` populated. |
| **TC-28.8-02** | Integration | Recalculation | Client total differs from server by > 0.01 | Import marked `conflict`; `conflict_notes` populated; 202 returned. |
| **TC-28.8-03** | Integration | Recalculation | Client total differs from server by ≤ 0.01 (tolerance) | Import marked `server_verified` (within tolerance). |
| **TC-28.8-04** | Integration | Product resolution | Import contains unknown product_id | Import marked `rejected`; `rejection_reason` contains `product_not_found`. |
| **TC-28.8-05** | Integration | Product resolution | Import contains inactive product | Import marked `rejected`; batch continues. |
| **TC-28.8-06** | Unit | Service | `recalculate()` returns structured result array | Shape: `{status, server_subtotal, server_tax_total, server_total, lines[]}`. |
| **TC-28.8-07** | Unit | Tax | Vatable item — server computes inclusive VAT correctly | `net = gross / 1.12`; `tax = gross - net` for 12% rate. |
| **TC-28.8-08** | Unit | Tax | VAT-exempt item — tax is 0 | `tax_amount = 0`; `vat_exempt_amount = gross`. |
| **TC-28.8-09** | Integration | Safety | After full sync, `reconciled_sale_id` is null on all imports | No official Sale created. |
| **TC-28.8-10** | Integration | Request | Import missing `client_total` field | 422 validation error. |
| **TC-28.8-11** | Integration | Idempotency | Batch replay still returns 200 after server_verified | Idempotency behaviour unchanged. |
| **TC-28.8-12** | Integration | Mixed batch | Batch with one server_verified, one conflict, one rejected | All three rows persisted; counts accurate in response. |

---

## 8. Response Shape Extension

`POST /api/pos/offline-sync` per-import result now includes recalculation fields:

```json
{
  "batch_id": "...",
  "batch_reference": "BATCH-001",
  "status": "completed",
  "submitted": 3,
  "processed": 2,
  "failed": 1,
  "imports": [
    {
      "offline_sequence_number": "INV-T01-0001",
      "status": "server_verified",
      "server_total": "1092.8600"
    },
    {
      "offline_sequence_number": "INV-T01-0002",
      "status": "conflict",
      "server_total": "1092.8600",
      "conflict_notes": "client_total=1200.00 server_total=1092.86 difference=107.14"
    },
    {
      "offline_sequence_number": "INV-T01-0003",
      "status": "rejected",
      "reason": "product_not_found:00000000-0000-0000-0000-000000000099"
    }
  ]
}
}
```

---

## 9. Validation & Results

- **Feature Tests**: `OfflineImportRecalculationTest.php` created with 12/12 scenarios passing.
- **Regression Tests**: `OfflineSyncValidationTest.php` and `OfflineSyncFoundationTest.php` updated and passing.
- **Full POS Suite**: 274/274 tests passed.
- **Full Regression**: Complete Admin, POS, and RBAC tests passed.

## 10. Governance Notes

- **Boundaries Maintained**: The implementation successfully executes the server-side economic verification layer and records `server_recalculation` and `conflict_notes` WITHOUT creating official `Sale` records, updating GCT, or touching inventory/payments/e-journal.
- **Tax Engine**: Properly integrated with the existing `SaleCreationService` pattern, using `Product::getSaleSnapshotBase()` to ensure parity between offline client expectations and server reality.
- **Rollout Limitations**: Production-grade development is verified, but formal CPA/BIR review remains deferred until post-development. Early partner pilot only.
