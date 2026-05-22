---
title: 'Spec 26.1-D: FEFO Allocation Service Foundation'
type: 'feature'
created: '2026-05-18'
status: 'completed'
context:
  - docs/roadmap/stories/story_26.1-c_fefo_allocation_planning.md
---

# Spec 26.1-D: FEFO Allocation Service Foundation

## Intent

**Problem:** We need a highly concurrent, reliable selection and depletion engine to allocate and deduct inventory using a First-Expired-First-Out (FEFO) strategy. When cashiers checkout products, the system must securely identify which active, unexpired lots are depleted, divide the requested quantities across multiple lots if necessary, decrement their remaining balances with maximum precision, and handle high-concurrency race conditions gracefully.

**Approach:** 
1. Create a dedicated standalone `FefoAllocationService` class under `app/Services/Inventory/`.
2. Implement an `allocate()` method that:
   - Queries `expiry_lots` for a specific product, branch, and tenant.
   - Enforces strict sorting criteria (`expiry_date ASC`, then `created_at ASC` tie-breaker).
   - Strictly excludes expired lots (`expiry_date <= current_date`) and inactive/depleted lots.
   - Performs pessimistic locking via Eloquent's `lockForUpdate()` to avoid double-allocation race conditions during high-volume periods.
   - Executes recursive partial depletion across multiple lots utilizing `bcmath` operations with a scale of `4`.
   - Modifies `quantity_remaining` and updates the lot status to `'depleted'` if the remaining quantity reaches `0.0000`.
   - Validates that the total unexpired inventory in matching lots is sufficient, throwing a dedicated `InsufficientStockException` if the request exceeds availability.
   - Returns a structured array output containing details of which lots were allocated and the exact quantity deducted from each.
3. Wrap all allocation logic inside an atomic database transaction. If any part of the depletion loop fails or if stock is insufficient, ensure the transaction is rolled back with no side-effects or lot mutations.
4. Author comprehensive service-level unit/integration tests in `tests/Feature/Inventory/FefoAllocationServiceTest.php` implementing the full `TC-FEFO` matrix.

---

## Boundaries & Constraints

**Always:**
- Execute the selection query using strict sorting: `expiry_date ASC`, then `created_at ASC`.
- Exclude all expired lots (`expiry_date <= CURRENT_DATE`) even if they have active stock.
- Use `bcmath` (`bcadd`, `bcsub`, `bccomp`) with a scale of `4` for all mathematical calculations.
- Employ pessimistic locking (`lockForUpdate()`) on all fetched `ExpiryLot` records inside a database transaction.
- Return a structured result containing allocation details (`expiry_lot_id`, `batch_code`, `quantity_allocated`).
- Throw a dedicated `App\Exceptions\Inventory\InsufficientStockException` (or similar) when the total unexpired lot stock is less than requested, ensuring the transaction is rolled back.

**Never:**
- Do not modify or integrate any POS checkout routes or controllers (e.g. `CheckoutController` or `SaleCreationService`).
- Do not modify any cashier frontend views or React components.
- Do not record stock movement logs (`stock_movements` table) in this slice. That integration is out-of-scope.

---

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
| :--- | :--- | :--- | :--- |
| Exact allocation from a single lot | Lot A (expires 10 days, qty = 10.0000). Request = 5.0000 | Returns allocation for Lot A (5.0000). Lot A `quantity_remaining` updated to 5.0000. Status remains `active`. | N/A |
| Split allocation across multiple lots | Lot A (expires 10 days, qty = 10.0000). Lot B (expires 20 days, qty = 10.0000). Request = 15.0000 | Returns allocations for Lot A (10.0000) and Lot B (5.0000). Lot A status becomes `depleted`. Lot B remains active (5.0000). | N/A |
| Insufficient stock across all lots | Lot A (expires 10 days, qty = 10.0000). Request = 15.0000 | Throws exception. Transaction rolled back. Lot A remains at 10.0000. | `InsufficientStockException` |
| Exclude expired lots | Lot A (EXPIRED, qty = 10.0000). Lot B (active, qty = 10.0000). Request = 5.0000 | Returns allocation for Lot B (5.0000). Lot A is untouched. | N/A |
| Concurrent requests (Race condition) | Two cashiers request 6.0000 each. Lot A (qty = 10.0000). | Cashier A locks Lot A and deducts 6.0000. Cashier B waits, then reads Lot A (qty = 4.0000), checks total, and throws `InsufficientStockException`. | `InsufficientStockException` |

---

## Code Map

- `app/Exceptions/Inventory/InsufficientStockException.php` -- Create custom exception for insufficient stock scenarios.
- `app/Services/Inventory/FefoAllocationService.php` -- Implement allocation logic with `bcmath` and `lockForUpdate()`.
- `tests/Feature/Inventory/FefoAllocationServiceTest.php` -- Create comprehensive unit/integration test suite verifying all FEFO scenarios.

---

## Tasks & Acceptance

**Execution:**
- [x] Create `InsufficientStockException`.
- [x] Create `FefoAllocationService` class under `app/Services/Inventory/`.
- [x] Implement the `allocate()` method with transaction wrapping, selection query, sorting, expired lot gating, recursive loop, and pessimistic locking.
- [x] Implement the `TC-FEFO` matrix inside `tests/Feature/Inventory/FefoAllocationServiceTest.php`.

**Acceptance Criteria:**
- [x] Lots are strictly selected in FEFO sequence: earliest expiration date first, then oldest database record first.
- [x] Expired lots (expiry date $\le$ current date) are strictly ignored in selection queries.
- [x] Quantities are accurately depleted using PHP `bcmath` operations with a scale of `4`.
- [x] When a lot is fully depleted, its remaining quantity is set to `0.0000` and status is set to `'depleted'`.
- [x] Concurrent requests are blocked via database row locks, preventing double allocation.
- [x] If available stock is insufficient, the system throws `InsufficientStockException` and rolls back the database transaction cleanly.
- [x] The full test suite runs successfully and passes.
