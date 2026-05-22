---
title: 'Story 26.3-F — Supplier Return Accounting Outbox Handoff'
type: 'feature'
created: '2026-05-18'
status: 'completed'
baseline_commit: '04a8d9dc067a89b47a8d1ed6f79fb05f7a02f52a'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Posted Supplier Returns (RMAs) mutate branch stock levels and update Weighted Average Cost (WAC) locally in IPOS, but these financial movements must also propagate reliably and asynchronously to QuickBooks Online (QBO) as Vendor Credits to reconcile accounts payable. Directly invoking external QBO APIs synchronously within checkout/logistics pipelines introduces external latency and risk of inconsistent state.

**Approach:** Implement a transactionally integrated Accounting Outbox handoff that records a standardized `supplier_return_posted` JSON event snapshot in the same database transaction as the RMA post. Update the accounting normalizers and QBO payload builders to map these outbox records to QBO `VendorCredit` entries using `ItemBasedExpenseLineDetail`, securing data immutability, tenant isolation, and strict idempotency.

## Boundaries & Constraints

**Always:**
- Execute outbox entry generation inside the existing pessimistic database transaction block in `SupplierReturnPostingService->post()`.
- Enforce strict composite uniqueness/idempotency constraints: `tenant_id + event_type + source_type + source_id`.
- Ensure all QBO maps exist (mapped product, mapped supplier) or fail validation early during payload building.
- Map the outbox event using the target QBO `VendorCredit` entity with `ItemBasedExpenseLineDetail` lines.
- Preserve full high-precision numerical formatting in the recorded outbox payload.

**Ask First:**
- N/A

**Never:**
- Allow direct external API requests (QBO sync) inside the HTTP controller thread/posting service.
- Allow payment execution, cash ledgering, supplier credit note emailing, or PDF/invoice printing within the outbox handoff service.
- Permit manual direct edits of outbox record payloads once written.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy Path Posting | Approved Supplier Return with valid items | Return is marked `posted`, inventory decremented, and matching `pending` outbox event is successfully created in database. | N/A |
| Duplication / Repost Attempt | Attempting to record outbox event for already posted Supplier Return | Prevented by pessimistic lock and active status check in service. DB unique constraint serves as final safety guard. | No duplicate entry generated. |
| Database Transaction Failure | Failure in outbox generation or inventory deduction | Entire state changes roll back completely, ensuring no partial writes (no stock deducted without outbox row, and vice versa). | Database transaction rolls back; custom exception thrown. |
| Unmapped Supplier mapping | QBO payload builder resolves a supplier lacking an active mapping | Builder throws validation runtime exception to mark sync attempt as failed without retrying (validation_failure). | Outbox Sync fails with validation error. |

</frozen-after-approval>

## Code Map

- `app/Services/Procurement/SupplierReturnPostingService.php` -- Handles RMA posting; will be updated to inject and call `AccountingOutboxService` inside the transaction.
- `app/Services/Accounting/Contracts/AccountingMapperInterface.php` -- Interface for QBO entity mappings; will be updated to include `mapSupplier` signature.
- `app/Services/Accounting/StaticAccountingMapper.php` -- Mock mapper for testing; will implement `mapSupplier`.
- `app/Services/Accounting/AccountingMappingService.php` -- Production mapper resolving dynamic mapping records; will implement `mapSupplier`.
- `app/Services/Accounting/NormalizedPayloadService.php` -- Translates raw events into standard financial payloads; will implement normalization for `supplier_return_posted`.
- `app/Services/Accounting/QuickBooksPayloadBuilderService.php` -- Builds QBO API requests; will implement `VendorCredit` builder and lines format.
- `tests/Feature/Procurement/SupplierReturnPostingTest.php` -- Feature test suite; will be expanded with TDD/BDD assertions for outbox handoff and rollback safety.

## Tasks & Acceptance

**Execution:**
- [x] `app/Services/Accounting/Contracts/AccountingMapperInterface.php` -- Add `mapSupplier(?string $posSupplierId): ?string` method signature to support mapping IPOS suppliers to QuickBooks vendor accounts.
- [x] `app/Services/Accounting/StaticAccountingMapper.php` -- Implement `mapSupplier` with predictable, unique output for test environments.
- [x] `app/Services/Accounting/AccountingMappingService.php` -- Implement `mapSupplier` resolving dynamic accounting mapping records from active mappings and add `'supplier'` to supportedTypes.
- [x] `app/Services/Accounting/NormalizedPayloadService.php` -- Implement `normalizeSupplierReturnPosted` and register `supplier_return_posted` event mapping.
- [x] `app/Services/Accounting/QuickBooksPayloadBuilderService.php` -- Implement QBO `VendorCredit` compilation (`buildVendorCredit` and `vendorCreditLines`) and register `supplier_return_posted` match.
- [x] `app/Services/Procurement/SupplierReturnPostingService.php` -- Inject `AccountingOutboxService` and invoke `recordEvent` right before committing transaction.
- [x] `tests/Feature/Procurement/SupplierReturnPostingTest.php` -- Implement comprehensive feature tests covering handoff, idempotency, rollback, and mapping error scenarios.

**Acceptance Criteria:**
- Given an approved Supplier Return, when a user with correct permissions posts it, then the inventory is decremented, the return status transitions to `posted`, and an immutable `AccountingOutbox` row is saved in the same transaction with a valid `supplier_return_posted` payload.
- Given an outbox record for `supplier_return_posted`, when `QuickBooksPayloadBuilderService->build()` is called, then the built payload matches the QBO `VendorCredit` entity structure with accurate `ItemBasedExpenseLineDetail` lines.
- Given a posting transaction, when an exception is thrown during outbox record generation, then the entire transaction is rolled back, and no inventory or document changes persist.

## Design Notes

### QuickBooks Online VendorCredit Mapping Structure
```json
{
  "provider": "quickbooks",
  "entity": "VendorCredit",
  "operation": "create",
  "idempotency_key": "ipos:{tenant_id}:supplier_return_posted:{supplier_return_id}",
  "tenant_id": "...",
  "branch_id": "...",
  "payload": {
    "DocNumber": "RMA-MOCK-20260518-0001",
    "CurrencyRef": { "value": "PHP" },
    "TotalAmt": 1500.00,
    "VendorRef": { "value": "SUPPLIER_XYZ" },
    "Line": [
      {
        "DetailType": "ItemBasedExpenseLineDetail",
        "Amount": 1500.00,
        "ItemBasedExpenseLineDetail": {
          "ItemRef": { "value": "ITEM_ABC" },
          "Qty": 10.0000,
          "UnitPrice": 150.00
        }
      }
    ],
    "PrivateNote": "IPOS supplier return {supplier_return_id}"
  }
}
```

## Verification

**Commands:**
- `./vendor/bin/pest tests/Feature/Procurement/SupplierReturnPostingTest.php` -- expected: all 26.3-D and 26.3-F outbox handoff tests pass successfully.
