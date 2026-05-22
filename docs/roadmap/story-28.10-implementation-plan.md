# Implementation Plan: Story 28.10 — Offline Import Official Posting & Reconciliation

**Status: IMPLEMENTED & LOCALLY VALIDATED**

## 1. Story Objective
Story 28.10 completes the server-side transition from reviewed offline imports into official `Sale` records. This is the first Epic 28 Phase 2 slice that is allowed to create official accounting and inventory effects, so the implementation must be extremely conservative.

The core rule is unchanged:

```md
Client offline payloads are claims.
Server recalculation is truth.
Official sale posting happens only on the server.
```

## 2. Scope Lock Summary

### In Scope
- Post only `server_verified` and `override_approved` offline imports.
- Create official `Sale`, `SaleItem`, and `Payment` records using server-authoritative values.
- Deduct inventory using the normal server-side inventory pipeline.
- Set `reconciled_sale_id` and `reconciled_at` only after successful commit.
- Preserve late-sync and prior-period reporting metadata.
- Audit log posting attempts and outcomes.
- Enforce idempotent re-post protection.

### Locked Implementation Decisions
- Accept fully paid payloads only.
- Reject underpaid or malformed payment payloads.
- Keep late-sync and prior-period classification informational in this story.
- Do not block posting solely because an import is late-sync.
- Do not reopen or mutate closed Z-read or GCT ledgers in this story.
- Treat `tests/Feature/Admin/OfflineImportPostingTest.php` as the canonical Story 28.10 acceptance suite.

### Out of Scope
- Local GCT, local Z-read, or local e-journal finalization.
- Frontend queue changes.
- Receipt printing changes.
- Admin review UI changes.
- Trusting client totals as official truth.

## 3. Current State Reconnaissance
The primary implementation surface already exists and should be reused rather than replaced.

### Existing posting entrypoint
- `app/Http/Controllers/Admin/OfflineImportController.php`
- Method: `postImport()`
- Current role: calls `OfflineReconciliationService::reconcileImport()` and returns success or `422` on runtime failure.

### Existing reconciliation service
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- Current role: contains `reconcileImport()` with locking, eligibility checks, invoice number generation, and official sale creation scaffolding.

### Existing test scaffold
- `tests/Feature/Admin/OfflineImportPostingTest.php`
- Current role: already describes the expected Story 28.10 behavior, including:
  - eligible status posting
  - override-approved posting
  - ineligible status rejection
  - idempotent repost protection
  - inventory deduction
  - payment persistence
  - rollback behavior

## 4. Implementation Hypothesis
Story 28.10 is not primarily a greenfield feature. It appears to be a hardening-and-completion slice around an already started posting flow.

The most likely successful approach is:
1. Keep `OfflineImportController::postImport()` as the single entrypoint.
2. Complete and harden `OfflineReconciliationService::reconcileImport()` so it becomes the sole official posting orchestrator.
3. Reuse existing POS sale, tax, payment, inventory, and audit services instead of writing a parallel offline-only posting stack.
4. Use tests in `OfflineImportPostingTest.php` as the primary acceptance gate.

## 5. Design Principles

### 5.1 Server-authoritative posting only
- Official totals must come from `server_recalculation`.
- `raw_payload` totals are diagnostic only.
- Product availability, tax, and inventory behavior must be driven by normal server logic.

### 5.2 Transactional atomicity
The posting flow must be wrapped in one transaction so these outcomes are all-or-nothing:
- sale creation
- sale item creation
- payment creation
- inventory deduction
- offline import reconciliation fields
- audit trail writes that are expected to commit with the posting

If any step fails, no partial sale may remain persisted.

### 5.3 Idempotent replay safety
If a previously posted import is submitted again:
- do not create a second sale
- do not deduct inventory again
- return success with the existing `sale_id`

### 5.4 Minimal surface area
Do not move posting logic into the frontend or admin UI.
Do not add new posting endpoints unless a blocker is proven.

## 6. Proposed Work Breakdown

### Phase A — Reconciliation service audit
Goal: verify whether `reconcileImport()` already satisfies the story contract or only partially does.

Tasks:
- inspect the full `reconcileImport()` flow
- trace which existing services it uses for:
  - sale creation
  - tax profile snapshotting
  - payment persistence
  - inventory deduction
  - invoice sequencing
  - audit logging
- identify any points where it writes data directly instead of using protected service logic

Deliverable:
- small gap list between current implementation and Story 28.10 requirements

### Phase B — Eligibility and idempotency hardening
Goal: make preconditions explicit and safe.

Tasks:
- allow posting only for:
  - `server_verified`
  - `override_approved`
- reject:
  - `conflict`
  - `hold`
  - `rejected`
  - `duplicate`
  - `pending`
  - `posted`
- preserve idempotent success path for already posted imports by returning the previously linked sale
- ensure the import row is locked with `lockForUpdate()` before branching

Target files:
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- `tests/Feature/Admin/OfflineImportPostingTest.php`

### Phase C — Official sale creation hardening
Goal: ensure the official sale reflects server recalculation, not client claims.

Tasks:
- derive line items from `server_recalculation['items']`
- ensure sale totals use `server_subtotal`, `server_tax_total`, and `server_total`
- confirm product tax buckets, rates, and snapshots come from the recalculation or canonical tax services
- preserve source metadata showing the sale came from offline reconciliation

Target files:
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- any reused POS sale creation service discovered in Phase A

### Phase D — Payment persistence and status resolution
Goal: safely convert optional raw payment payloads into official payment records.

Tasks:
- accept payments only when payload format is valid
- create payment rows using controlled server logic
- mark sale status appropriately:
  - `paid` when payment fully covers total
  - otherwise use existing POS status rules
- reject malformed payment payloads inside the transaction if they would create partial or inconsistent posting

Target files:
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- payment-related services reused from normal POS checkout

### Phase E — Inventory deduction and rollback integrity
Goal: ensure stock effects are identical to normal server-created sales.

Tasks:
- route inventory deduction through the normal checkout or inventory service path
- preserve FEFO or inventory tracking constraints already enforced elsewhere
- verify that failure after deduction rolls back fully

Target files:
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- any reused inventory/checkout services identified in Phase A

### Phase F — Reconciliation finalization and audit trail
Goal: finalize import status only after the official sale commit succeeds.

Tasks:
- set `status = posted`
- set `reconciled_sale_id`
- set `reconciled_at`
- preserve late-sync classification fields and prior-period metadata
- log success and failure attempts with enough context for audit review

Target files:
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- audit/logging services already used by POS and compliance flows

## 7. File Map (Expected)

### Primary implementation files
- `app/Services/POS/OfflineSync/OfflineReconciliationService.php`
- `app/Http/Controllers/Admin/OfflineImportController.php`

### Likely reused service dependencies
- invoice sequence service already used by POS posting
- sale creation service already used by normal checkout
- payment persistence service already used by normal checkout
- inventory deduction service already used by normal checkout
- audit logging service already used elsewhere in POS/compliance

### Primary validation files
- `tests/Feature/Admin/OfflineImportPostingTest.php`
- possibly supporting feature tests if Phase A reveals missing coverage in inventory or payment paths

## 8. Acceptance Test Plan
These tests should be the closure gate for Story 28.10.

### Core posting tests
- `server_verified` import can be posted successfully.
- `override_approved` import can be posted successfully.
- ineligible statuses are rejected.
- repost returns the same `sale_id` without duplicating side effects.

### Financial integrity tests
- sale totals come from `server_recalculation`, not from raw payload client claims.
- sale items mirror server recalculation lines.
- valid payments create official payment records.
- malformed payment payloads do not leave partial posting state.

### Inventory integrity tests
- tracked inventory decrements correctly.
- rollback restores inventory if posting fails mid-transaction.

### Reconciliation integrity tests
- `reconciled_sale_id` and `reconciled_at` are set only on success.
- failed attempts leave import unposted.
- late-sync metadata remains attached and queryable.

### Offline metadata preservation tests
- created `Sale` stores `source = offline_reconciliation`.
- created `Sale` stores `offline_sales_import_id`.
- created `Sale` stores `offline_sequence_number`.
- created `Sale` stores `offline_submitted_at` and optional `offline_local_created_at`.
- created `Sale` stores posting timestamp (`offline_posted_at`).

### Audit tests
- success attempts are logged.
- rejected or failed attempts are logged.

## 9. Risks To Watch During Implementation

### R1 — bypassing existing sale safeguards
Risk:
Creating sale rows directly could skip protections already encoded in normal POS flows.

Mitigation:
Prefer reuse of the same sale creation path the online checkout uses.

### R2 — trusting stale recalculation content blindly
Risk:
`server_recalculation` may contain values that need normalization before persistence.

Mitigation:
Validate its required fields explicitly before sale creation.

### R3 — payment/inventory partial commits
Risk:
A failure after creating payment rows or deducting inventory could leave inconsistent state.

Mitigation:
Keep the entire reconcile flow in one DB transaction and test rollback behavior explicitly.

### R4 — hidden duplicate posting path
Risk:
A second path may call posting logic without honoring idempotency.

Mitigation:
Keep `OfflineReconciliationService::reconcileImport()` as the single posting authority and verify controller usage.

## 10. Review Questions
This plan is approved for implementation with the locked decisions above.

## 11. Recommended Execution Order
```md
1. Audit reconcileImport() end-to-end
2. Lock eligibility and idempotency rules
3. Harden official sale creation from server_recalculation
4. Wire payment persistence through existing server logic
5. Verify inventory deduction and rollback integrity
6. Finalize reconciliation fields and audit logs
7. Expand/fix feature tests until Story 28.10 acceptance suite is green
```

## 12. Governance Statement
Story 28.10 must remain a strictly server-side posting slice. It may convert reviewed offline imports into official sales, but it must not shift financial truth to the client, and it must not introduce local official invoice, GCT, Z-read, or e-journal finalization behavior.
