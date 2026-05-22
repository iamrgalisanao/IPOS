---
title: 'Spec 26.1-B: Receiving-Time Expiry Capture Validation'
type: 'feature'
created: '2026-05-18'
status: 'completed'
baseline_commit: '04a8d9dc067a89b47a8d1ed6f79fb05f7a02f52a'
context:
  - _bmad-output/implementation-artifacts/spec-26-1-a-expiry-lot-schema-model-foundation.md
---

# Spec 26.1-B: Receiving-Time Expiry Capture Validation

## Intent

**Problem:** Perishable goods entered into inventory must have their expiration dates recorded at the time of goods receiving. Currently, when draft receiving vouchers are created or updated, the system does not enforce that perishable products (products with `expiry_tracking_enabled = true`) have valid expiration dates. Furthermore, posting a receiving voucher does not create or update records in the `expiry_lots` table, which is the foundational ledger for FEFO tracking.

**Approach:** 
1. Add strict request validation inside `PurchaseReceivingController` during both draft creation (`store`) and update (`update`) operations. For any line containing a product with `expiry_tracking_enabled = true`, require an `expiry_date` that is a valid date and is greater than or equal to the `received_at` date.
2. In `PurchaseReceivingPostingService`, automatically create or update records in the `expiry_lots` table when a receiving voucher is posted:
   - For each receiving line with a product requiring expiry tracking, locate or create a matching `ExpiryLot` using the unique key composite: `[tenant_id, branch_id, product_id, batch_code]`.
   - Use the line's `lot_number` as the `batch_code`. If `lot_number` is empty, auto-generate a unique batch code in a repeatable format: `LOT-[receiving_number_without_dashes]-[line_id_suffix]` to prevent database unique-constraint failure while keeping the capture of batch codes optional.
   - For updates to an existing lot, safely increment both `quantity_received` and `quantity_remaining` by the line's `received_quantity` using high-precision decimal operations (`bcadd`).
   - For new lots, populate `quantity_received`, `quantity_remaining`, `expiry_date`, and set `status = 'active'`.
3. Add branch/tenant/product safety checks to ensure no cross-contamination can occur.
4. Author comprehensive feature tests inside `tests/Feature/Procurement/PurchaseReceivingExpiryCaptureTest.php` covering all scenarios.

---

## Boundaries & Constraints

**Always:**
- Enforce that `expiry_date` is strictly required on draft create (`store`) and update (`update`) when the associated product has `expiry_tracking_enabled = true`.
- Ensure `expiry_date` is a valid date and must be greater than or equal to the `received_at` date.
- Allow `lot_number` (batch code) to remain optional during data entry. If not provided, the posting service must generate a unique, clean, database-compliant fallback code: `LOT-[receiving_number_without_dashes]-[line_id_suffix]` (using the last 8 characters of the line UUID).
- Ensure `ExpiryLot` operations utilize high-precision decimal addition (`bcadd`) with scale `4` to prevent float rounding issues.
- Enforce strict branch/tenant isolation: a tenant can only receive lots into their own branches and associate them with their own products.

**Never:**
- Do not implement POS checkout deduction logic or FEFO sales-allocation queries.
- Do not block cashier-level checkouts of expired stocks.
- Do not implement a near-expiry alert dashboard, notifications, or suppliers' RMA/returns modules.

---

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
| :--- | :--- | :--- | :--- |
| Perishable product with expiry_date | Create draft with a perishable product and valid `expiry_date` | Succeeds, draft saved with line's `expiry_date` and `lot_number`. | N/A |
| Perishable product missing expiry_date | Create/update draft with a perishable product but `expiry_date` is null/empty | Fails validation with structured message keyed to the line index. | `ValidationException` with error `"lines.{index}.expiry_date"` |
| Non-perishable product missing expiry | Create/update draft with non-perishable product and null `expiry_date` | Succeeds, draft saved successfully. | N/A |
| Posting perishable receiving (new lot) | Post draft with perishable product (e.g. lot code `BATCH-A`) | `ExpiryLot` record created with active status and quantity remaining = quantity received. | N/A |
| Posting perishable receiving (existing lot) | Post draft with perishable product under existing lot `BATCH-A` | `ExpiryLot` quantities incremented by the received quantity. | N/A |
| Posting perishable receiving (no lot number) | Post draft with perishable product but `lot_number` is blank | `ExpiryLot` record created with a unique fallback batch code. | N/A |

---

## Code Map

- `app/Http/Controllers/Procurement/PurchaseReceivingController.php` -- Enhance `store` and `update` validation to require `expiry_date` for perishable products.
- `app/Services/Procurement/PurchaseReceivingPostingService.php` -- Populate/update `expiry_lots` during posting for perishable products.
- `tests/Feature/Procurement/PurchaseReceivingExpiryCaptureTest.php` -- Complete feature test suite verifying perishable and non-perishable flows, validation rules, unique fallback generation, and tenant safety.

---

## Tasks & Acceptance

**Execution:**
- [x] Implement request validation check in `PurchaseReceivingController@store` and `PurchaseReceivingController@update` for perishable products.
- [x] Update `PurchaseReceivingPostingService@post` to write lot entries to the `expiry_lots` table when posting perishable products.
- [x] Auto-generate unique repeatable fallback lot codes when `lot_number` is not provided.
- [x] Write comprehensive validation and functional tests in `tests/Feature/Procurement/PurchaseReceivingExpiryCaptureTest.php`.

**Acceptance Criteria:**
- [x] Perishable receiving drafts cannot be stored or updated without a valid `expiry_date` that is equal to or after `received_at`.
- [x] Validation errors are precisely keyed by line index so frontend can map them cleanly.
- [x] Non-perishable items can be received without an expiry date or lot number.
- [x] Posting a draft successfully creates new lots or increments existing lots in the `expiry_lots` table for perishable items.
- [x] If no lot number is provided for a perishable item, a unique fallback batch code is gracefully and uniquely generated.
- [x] All tests pass with 100% correctness.

---

## Verification

**Commands & Results:**
- `PurchaseReceivingExpiryCaptureTest` (6 / 6 tests passed, 31 assertions)
  ```bash
  ./vendor/bin/pest --filter=PurchaseReceivingExpiryCaptureTest
  # Output: {"tool":"pest","result":"passed","tests":6,"passed":6,"assertions":31,"duration_ms":846}
  ```
- `PurchaseReceivingPostingTest` (4 / 4 tests passed, 19 assertions)
  ```bash
  ./vendor/bin/pest --filter=PurchaseReceivingPostingTest
  # Output: {"tool":"pest","result":"passed","tests":4,"passed":4,"assertions":19,"duration_ms":752}
  ```
- `PurchaseReceivingDraftTest` (15 / 15 tests passed, 52 assertions)
  ```bash
  ./vendor/bin/pest --filter=PurchaseReceivingDraftTest
  # Output: {"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":52,"duration_ms":1417}
  ```
- All Procurement Tests (54 / 54 tests passed, 294 assertions)
  ```bash
  ./vendor/bin/pest tests/Feature/Procurement
  # Output: {"tool":"pest","result":"passed","tests":54,"passed":54,"assertions":294,"duration_ms":4305}
  ```
