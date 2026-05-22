---
title: 'Story 26.4-B — Supplier Invoice Schema Foundation'
type: 'feature'
created: '2026-05-18'
status: 'completed'
baseline_commit: '04a8d9dc067a89b47a8d1ed6f79fb05f7a02f52a'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** To support 3-Way accounts payable (AP) matching, IPOS must be able to record external supplier billing documents (Supplier Invoices) securely and link them back directly to original Purchase Orders (POs) and Goods Receipt Vouchers (GRVs / Purchase Receivings). Without a dedicated, relational database schema and model structure for these invoices, there is no place to calculate match variances or queue liabilities for accounting synchronization.

**Approach:** Create the database migration schema and Eloquent model framework for `SupplierInvoice` and `SupplierInvoiceLine` tables. Configure all required references (Tenant, Branch, Supplier, Purchase Order, and Purchase Receivings / GRV), enforce strict unique indexing constraints on `(tenant_id, supplier_id, invoice_number)` to prevent duplicate billing entries, establish model relationships, and validate schema integrity through comprehensive model-level unit tests.

## Boundaries & Constraints

**Always:**
- Include strict multi-tenant foreign keys (`tenant_id`, `branch_id`) to ensure absolute tenant isolation.
- Enforce unique index composite checks at the database layer on `(tenant_id, supplier_id, invoice_number)`.
- Use high-precision decimal formatting (e.g., `decimal(15, 4)` for costs, subtotals, and taxes; `decimal(12, 4)` for product quantities) to prevent rounding float issues.
- Define a safe default state of `pending` for the invoice `match_status`.
- Define standard Eloquent relationships: `belongsTo` for Tenant, Branch, Supplier, PurchaseOrder, and `hasMany` for lines.

**Ask First:**
- N/A

**Never:**
- Implement matching algorithm checking services in this story (that belongs in `26.4-C`).
- Implement controller routes, invoice posting controllers, or JSON request validation endpoints (that belongs in subsequent stories).
- Implement accounting outbox event dispatch hooks or QBO `Bill` payload builder mapping classes.
- Introduce AP cash payments, payment approvals, or automated bank/supplier messaging logic.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy Path Invoice Insertion | Valid supplier, branch, PO, and GRV references; unique invoice number | Database record successfully created with safe default status `pending`. | N/A |
| Duplicate Supplier + Invoice Number | Inserting invoice number `INV-COCA-9988` for Tenant A + Supplier X twice | Database unique constraint rejects insertion attempt immediately. | `QueryException` thrown (Integrity constraint violation). |
| Cross-Tenant Reference | Attempting to link Tenant A's Invoice to Tenant B's Purchase Order | Fails foreign key validation or application constraint checks. | SQL Foreign Key Integrity violation. |
| Negative Financial Quantities | Negative total/subtotal values passed | Blocked by migration unsigned bounds or validation checks if specified. | Validation/Database exceptions. |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_05_18_195500_create_supplier_invoices_table.php` -- Migration to create `supplier_invoices` and `supplier_invoice_lines` with indexes.
- `app/Models/Procurement/SupplierInvoice.php` -- Eloquent model for supplier invoice documents, complete with tenant traits and relationships.
- `app/Models/Procurement/SupplierInvoiceLine.php` -- Eloquent model for line items.
- `app/Models/Tenant.php` -- Extend with `supplierInvoices` relation.
- `app/Models/Supplier.php` -- Extend with `supplierInvoices` relation.
- `tests/Feature/Procurement/SupplierInvoiceSchemaTest.php` -- Test suite validating migrations, relationships, uniqueness constraints, and default states.

## Tasks & Acceptance

**Execution:**
- [x] **Migration**: Write migration for `supplier_invoices` and `supplier_invoice_lines` defining:
  - High precision decimal types (`15,4` and `12,4`).
  - Unique compound index: `['tenant_id', 'supplier_id', 'invoice_number']`.
  - Proper foreign keys with cascaded deletes for lines.
- [x] **Eloquent Models**: Create `SupplierInvoice` and `SupplierInvoiceLine` models:
  - Enforce `BelongsToTenant` trait isolation.
  - Define `match_status` values (`pending`, `matched`, `discrepant`, `posted`).
  - Setup relationships to `Tenant`, `Branch`, `Supplier`, `PurchaseOrder`, `PurchaseReceiving`, and lines.
- [x] **Parent Relationships**: Add inverse `hasMany` relationships to `Tenant` and `Supplier` models.
- [x] **Test Coverage**: Write `SupplierInvoiceSchemaTest.php` to prove schema assertions and model validations under TDD rules.

**Acceptance Criteria:**
- Given a tenant and a supplier, when an invoice is saved with a new invoice number, then it inserts successfully with `match_status = 'pending'`.
- Given an existing invoice record in the database, when an attempt is made to insert another invoice with the identical invoice number for the same supplier and tenant, then the database rejects the insertion.
- Given a `SupplierInvoice` instance, when querying relationships, then it successfully returns corresponding `Supplier`, `Branch`, `PurchaseOrder`, and `SupplierInvoiceLine` items.

## Design Notes

### Schema Layout Reference:
```sql
CREATE TABLE supplier_invoices (
    id CHAR(36) PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    branch_id CHAR(36) NOT NULL,
    supplier_id CHAR(36) NOT NULL,
    purchase_order_id CHAR(36) NULL,
    invoice_number VARCHAR(255) NOT NULL,
    invoice_date DATE NOT NULL,
    subtotal DECIMAL(15,4) NOT NULL,
    tax_total DECIMAL(15,4) NOT NULL,
    total_amount DECIMAL(15,4) NOT NULL,
    match_status VARCHAR(50) NOT NULL DEFAULT 'pending',
    posted_at TIMESTAMP NULL,
    posted_by CHAR(36) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uq_tenant_supplier_invoice (tenant_id, supplier_id, invoice_number)
);

CREATE TABLE supplier_invoice_lines (
    id CHAR(36) PRIMARY KEY,
    supplier_invoice_id CHAR(36) NOT NULL,
    purchase_receiving_line_id CHAR(36) NULL,
    product_id CHAR(36) NOT NULL,
    quantity_billed DECIMAL(12,4) NOT NULL,
    unit_cost_billed DECIMAL(15,4) NOT NULL,
    line_total DECIMAL(15,4) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

## Verification

**Migrations:**
- Running `php artisan migrate` completed successfully, creating the database schema for both `supplier_invoices` and `supplier_invoice_lines`.

**Focused Test Run:**
- Run target schema feature test suite:
  ```bash
  ./vendor/phpunit/phpunit/phpunit tests/Feature/Procurement/SupplierInvoiceSchemaTest.php
  ```
- Output:
  ```json
  {"tool":"phpunit","result":"passed","tests":6,"passed":6,"assertions":27,"duration_ms":761}
  ```

**Procurement Regression Suite:**
- Run full regression tests for the Procurement domain:
  ```bash
  ./vendor/phpunit/phpunit/phpunit tests/Feature/Procurement
  ```
- Output:
  ```json
  {"tool":"phpunit","result":"passed","tests":97,"passed":97,"assertions":548,"duration_ms":5993}
  ```

