---
title: 'Spec 26.1-E: FEFO POS Transaction Integration'
type: 'feature'
created: '2026-05-18'
status: 'completed'
context:
  - docs/roadmap/stories/story_26.1-c_fefo_allocation_planning.md
---

# Spec 26.1-E: FEFO POS Transaction Integration

## Intent

**Problem:** When a POS sale is processed, if the checkout cart contains perishable items (marked `expiry_tracking_enabled = true`), the system must automatically select and deplete stock from unexpired lots using a First-Expired-First-Out (FEFO) strategy. If there is insufficient stock in active, unexpired lots, the entire sale creation must be cleanly aborted, with all partial database mutations completely rolled back to prevent stock corruption and inventory discrepancy.

**Approach:** 
1. **Constructor Injection**: Inject `FefoAllocationService` into `SaleCreationService` via constructor injection.
2. **Transaction Loop Integration**: Inside the `DB::transaction()` callback of `SaleCreationService::createFromPayload()`, check each item being purchased:
   - Load the target `Product` from database.
   - If `$product->expiry_tracking_enabled` is true:
     - Invoke `FefoAllocationService::allocate()` to systematically lock and decrement matching `ExpiryLot` quantities in FEFO order.
     - If the unexpired stock is insufficient, the service throws `InsufficientStockException`, which bubbles up and automatically triggers a database transaction rollback of all changes (preventing any partial sale records or partial lot mutations).
   - If `$product->expiry_tracking_enabled` is false:
     - Process the item using standard checkout logic (bypassing FEFO).
3. **Integration Verification**: Create `tests/Feature/POS/SaleCreationFefoTest.php` validating:
   - Perishable products checkout successfully, decrementing lots in correct FEFO order.
   - Non-perishable products checkout successfully under normal rules.
   - Failure to allocate perishable product due to insufficient unexpired stock throws `InsufficientStockException`, rolling back all sale records and leaving lot states untouched (no partial deductions).
   - Multi-item carts where one item fails to allocate roll back the entire transaction.

---

## Boundaries & Constraints

**Always:**
- Apply FEFO allocation inside the active database transaction of `SaleCreationService::createFromPayload()`.
- Ensure `InsufficientStockException` bubbles up and is handled by the existing checkout/API exception layer, or explicitly tested as the thrown domain exception for this slice.
- Ensure that non-perishable checkouts remain completely unaffected.
- Keep quantities represented using high-precision `bcmath` operations with a scale of `4`.

**Never:**
- Do not modify cashier frontend views or React components.
- Do not record stock movement logs (`stock_movements` table) in this slice. That integration is out-of-scope. Only `expiry_lots.quantity_remaining` and `expiry_lots.status` may be changed by FEFO allocation in this slice.
- Do not implement near-expiry dashboard alerts or supplier returns logic.

---

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
| :--- | :--- | :--- | :--- |
| Perishable Checkout (Success) | Lot A (expires in 10 days, qty = 10). Product B is perishable. Request 5.0000 | Sale created successfully. Lot A `quantity_remaining` updated to 5.0000. | N/A |
| Mixed Checkout (Success) | Perishable Item A (Lot qty = 10) + Normal Item B (qty = 5) | Sale created successfully. Perishable Item A depletes lot correctly. Normal Item B processes normally. | N/A |
| Perishable Checkout (Failure) | Lot A (expires in 10 days, qty = 10). Request 15.0000 | Transaction rolled back. No Sale or SaleItems created. Lot A remains at 10.0000. | Throws `InsufficientStockException` |
| Multi-Item Failure (Clean Rollback) | Normal Item A + Perishable Item B (lot qty = 5, request = 10) | Transaction rolled back. No Sale or SaleItems created. Normal Item A remains untouched. Perishable Item B remains at 5.0000. | Throws `InsufficientStockException` |
| Non-perishable checkout does not touch lots | Normal Item A + Unrelated active lots existing in database for Product A. | Normal checkout succeeds. No database mutations occur on any `expiry_lots` rows. | N/A |
| Expired-only lot failure | Perishable Product A + Expired-only lots in database (qty = 10). Request = 5.0000 | Transaction rolled back. No Sale or SaleItems created. Expired lots remain untouched. | Throws `InsufficientStockException` |

---

## Code Map

- `app/Services/POS/SaleCreationService.php` -- Inject `FefoAllocationService` and call `allocate()` inside the transaction loop for perishable products.
- `tests/Feature/POS/SaleCreationFefoTest.php` -- Author comprehensive integration tests verifying checkout scenarios, clean transaction rollback, and boundary checks.

---

## Tasks & Acceptance

**Execution:**
- [x] Inject `FefoAllocationService` into `SaleCreationService`.
- [x] Implement FEFO lot depletion loop inside `SaleCreationService::createFromPayload()` under `DB::transaction()`.
- [x] Author `tests/Feature/POS/SaleCreationFefoTest.php`.
- [x] Run test suites to verify 100% green correctness.

**Acceptance Criteria:**
- [x] Cart checkouts containing perishable products (`expiry_tracking_enabled = true`) accurately deplete available unexpired lots in FEFO order.
- [x] Cart checkouts containing only normal products (`expiry_tracking_enabled = false`) bypass FEFO lot depletion completely.
- [x] If any lot allocation fails (due to insufficient stock or expired lots), the transaction is immediately rolled back: no sale is recorded, no checkout request is updated, and all lot quantities are fully restored (nested transactions roll back cleanly restoring both sale records and lot quantities).
- [x] Non-perishable checkout does not touch unrelated expiry lots.
- [x] Expired-only lots are strictly excluded from allocation, causing checkout failure with `InsufficientStockException` and clean rollback.
- [x] The full test suite runs successfully and passes.
