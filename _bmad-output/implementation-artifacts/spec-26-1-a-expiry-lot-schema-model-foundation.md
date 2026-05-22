---
title: 'Spec 26.1-A: Expiry Lot Schema & Model Foundation'
type: 'feature'
created: '2026-05-18'
status: 'completed'
baseline_commit: '04a8d9dc067a89b47a8d1ed6f79fb05f7a02f52a'
context: []
---

# Spec 26.1-A: Expiry Lot Schema & Model Foundation

## Intent

**Problem:** To support advanced supply chain operations (FEFO allocation, expiration tracking, expired stock blocking), the system needs a core data structure for lot batches and expiration dates, along with a catalog toggle on products to denote whether they require expiry tracking. Currently, the database lacks both the `expiry_lots` table and the product capability toggle.

**Approach:** Implement a database migration to add `expiry_tracking_enabled` to the `products` table and create the `expiry_lots` table. Scaffold the `ExpiryLot` Eloquent model with proper relations (`Tenant`, `Branch`, `Product`, `PurchaseReceivingLine`), tenant isolation trait, status/expiry casts, and write base model unit tests validating boundaries and schema constraints.

---

## Boundaries & Constraints

**Always:**
- Keep this foundation strictly locked to database schema definitions, Eloquent model classes, seeders, and model relationship unit/feature tests.
- Add `expiry_tracking_enabled` as a boolean field with a default value of `false` on the `products` table.
- Include a composite unique index on `[tenant_id, branch_id, product_id, batch_code]` on `expiry_lots` to prevent duplicate batch registration within the same store environment.
- Enforce the `BelongsToTenant` trait on `ExpiryLot` model.
- Configure foreign key constraints matching existing project key types with Cascade Delete on `tenant_id`, `branch_id`, and `product_id`.
- Ensure `purchase_receiving_line_id` is a nullable foreign key with `nullOnDelete()` protection.
- Mapped fields `quantity_received` and `quantity_remaining` must use `decimal(19,4)` representation and include database-level check constraints verifying values are `>= 0`.

**Ask First:**
- Adding additional fields to the `expiry_lots` table not originally defined in the ER model.

**Never:**
- Do not implement any POS checkout changes, automatic stock deductions, or FEFO allocation logic.
- Do not modify checkout validation logic or inventory deductions in `SaleCreationService.php`.
- Do not implement receiving-time expiry capture workflows or modify controllers under `/procurement/receiving`.
- Do not implement near-expiry alert dashboards or alert widgets in this story slice.

---

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
| :--- | :--- | :--- | :--- |
| Happy Path: Lot Ingestion | Save ExpiryLot with valid dates, positive quantities, and unexpired date | Database persists record successfully; status is active. | N/A |
| Error Path: Negative Quantity | Save ExpiryLot with negative quantity | database check constraint fails. | QueryException / DB State Violation |
| Error Path: Past Expiration | Save ExpiryLot with expired date | database persists (model validation can warn, but raw DB allows). | N/A |
| Duplicate Lot Ingestion | Insert duplicate `[tenant_id, branch_id, product_id, batch_code]` | Database query throws duplicate key exception. | QueryException / UniqueConstraintViolationException |

---

## Code Map

- `app/Models/ExpiryLot.php` -- The new ExpiryLot Eloquent model with multi-tenant and relationship setups.
- `app/Models/Product.php` -- Map relationship `expiryLots` and cast `expiry_tracking_enabled` boolean.
- `app/Models/Tenant.php` -- Map relationship `expiryLots`.
- `app/Models/Branch.php` -- Map relationship `expiryLots`.
- `database/migrations/2026_05_18_000000_add_expiry_tracking_to_products_table.php` -- Adds product-level toggle column.
- `database/migrations/2026_05_18_000001_create_expiry_lots_table.php` -- Creates primary batch/lot tracking schema with check constraints.
- `tests/Feature/Inventory/ExpiryLotModelTest.php` -- Feature/Unit test suite confirming model relations, validation, cascade deletes, and tenant isolation.

---

## Tasks & Acceptance

**Execution:**
- [x] `database/migrations/2026_05_18_000000_add_expiry_tracking_to_products_table.php` -- Create migration adding `expiry_tracking_enabled` to products.
- [x] `database/migrations/2026_05_18_000001_create_expiry_lots_table.php` -- Create migration for `expiry_lots` with foreign key constraints, decimal precisions with non-negative checks, status, and composite unique indexes.
- [x] `app/Models/ExpiryLot.php` -- Scaffold model, casting status and dates, and utilizing the `BelongsToTenant` trait.
- [x] `app/Models/Product.php` -- Add `expiry_tracking_enabled` to fillable/casts, and configure the `expiryLots` relationship.
- [x] `app/Models/Branch.php` -- Configure the `expiryLots` relationship.
- [x] `app/Models/Tenant.php` -- Configure the `expiryLots` relationship.
- [x] `tests/Feature/Inventory/ExpiryLotModelTest.php` -- Write tests for tenant boundary enforcement, unique keys, non-negative db limits, and relationship mapping.

**Acceptance Criteria:**
- Given an unmigrated database, when migrations are run, then the products and expiry_lots tables are correctly structured.
- Given a newly created ExpiryLot, when querying via a tenant session, then standard global scoping automatically filters results to that tenant.
- Given a duplicate batch code for the same branch and product, when attempting to write, then the database blocks the entry via the unique key constraint.

---

## Verification

**Commands:**
- `php artisan migrate:fresh` -- expected: Database migrates successfully with zero errors.
- `./vendor/bin/pest --filter=ExpiryLotModelTest` -- expected: All unit/feature tests for model relations, boundaries, constraints, and cascading deletes pass.
