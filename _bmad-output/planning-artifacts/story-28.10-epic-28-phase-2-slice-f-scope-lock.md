# Story 28.10: Epic 28 Phase 2 Slice F — Offline Import Official Posting & Reconciliation

**Date**: 2026-05-20  
**Status**: Planning Only / Scope Locked  
**Implementation Phase**: Not Started  

---

## 1. Goal

Convert eligible offline imports into official server-side `Sale` records using server-authoritative calculations and existing checkout/sale creation safeguards.

This is the most sensitive slice as it finally crosses from offline import review into official sales persistence. We must be exceptionally strict to ensure financial integrity.

---

## 2. Story Scope Boundaries

### In Scope

- **Posting Eligibility Rules**: Only `server_verified` and `override_approved` imports can be posted.
- **Idempotent Posting Guard**: Once an import is posted, it must not be posted again.
- **Official Sale Creation**: Generate official `Sale` records using server-authoritative recalculation results, NOT client-submitted totals.
- **Payment Persistence**: Create official payment records if the payload contains valid payment data.
- **Inventory Deduction**: Process inventory deductions using the existing server-side flow.
- **Reconciliation Audit**: Update `reconciled_sale_id` and `reconciled_at` only after successful official posting.
- **Posting Audit Logs**: Ensure every posting attempt (success or failure) is logged.
- **Late-sync Classification**: Preserve late-sync status and prior-period metadata during posting.

### Out of Scope

- Local official GCT generation.
- Local Z-read generation.
- Local official e-journal finalization.
- Accepting client totals as official truth.
- Frontend offline queue.
- Offline receipt printing changes.

---

## 3. Hard Rules for Posting

1. **Server Authority**: Posting MUST use server-authoritative recalculation. Client totals are strictly treated as claims, not trusted facts.
2. **Sale Logic Reuse**: Posting MUST create the official `Sale` through existing server-side sale creation logic or a controlled reconciliation service to ensure all standard POS safeguards apply.
3. **Idempotency**: Posting MUST be idempotent. A posted import must immediately exit any subsequent processing attempts.
4. **Audit Immutability**: `reconciled_sale_id` and `reconciled_at` MUST be updated ONLY after a fully successful transaction commit of the official posting.
5. **Inventory Integrity**: Inventory deduction MUST use the existing server inventory flow to ensure proper stock depletion and Fefo constraints.
6. **Payment Integrity**: Payment creation MUST use controlled server logic.
7. **Reporting Integrity**: GCT and Z-read aggregation logic MUST remain server-authoritative.
8. **Late-sync Traceability**: Late-sync / prior-period imports MUST be classified clearly to assist accounting reports.
9. **No Re-posting**: Posted imports MUST NOT be posted again under any circumstances.
10. **Traceability**: EVERY posting attempt MUST be audit logged.

---

## 4. Posting Eligibility

| Status | Eligible for Posting? |
| :--- | :--- |
| `server_verified` | **Yes** |
| `override_approved` | **Yes** |
| `conflict` | No |
| `hold` | No |
| `rejected` | No |
| `duplicate` | No |
| `posted` | No |

---

## 5. Test Matrix Requirements

- Verify only `server_verified` and `override_approved` imports can be posted.
- Verify `reconciled_sale_id` and `reconciled_at` are accurately set upon success.
- Verify idempotency (prevent double posting).
- Verify server recalculation totals are used over client claims for the official `Sale` and `SaleItem`.
- Verify inventory deductions trigger correctly based on server logic.
- Verify payments are accurately recorded if provided in payload.
- Verify transaction atomicity (failures must rollback without partial data).
- Verify audit logging of posting attempts.

---

## 6. Governance Note

This slice is strictly bounded to server-side posting. It explicitly prevents any client-side truth dominance. All computations, inventory movements, and financial logs rely on the existing, certified server-side POS engine. 
