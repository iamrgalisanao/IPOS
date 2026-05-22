# Story 28.6: Epic 28 Phase 2 Slice B — Offline Import Schema & Reconciliation Foundation

**Date**: 2026-05-20  
**Status**: Implemented & Locally Validated  
**Implementation Phase**: Complete  

---

## 1. Goal

Define the precise business rules, architectural constraints, database blueprints, and validation checks for **Epic 28 Phase 2 Slice B — Offline Import Schema & Reconciliation Foundation**.

This slice establishes the **server-side data ledger and reconciliation skeleton** upon which all future offline sale ingestion, deduplication, sequence validation, and provisional-to-official reconciliation flows depend.

Story 28.5 established that a terminal *can* go offline and *what prefix it uses*. Story 28.6 defines *how offline captures are imported*, *how batches are tracked*, and *how sequence recovery events are recorded*. It does **not** implement the frontend offline queue or any local sale capture logic.

## Validation Evidence

```
./vendor/bin/pest tests/Feature/POS/OfflineSyncFoundationTest.php
Result: 18/18 tests passing

./vendor/bin/pest tests/Feature/Admin/ tests/Feature/POS/ tests/Feature/RbacEnforcementTest.php
Result: 275/275 tests passing
```

## Governance Note

Story 28.6 implements offline sync schema and reconciliation foundation only. It does **not** implement actual offline sales ingestion, sale posting, frontend queueing, local official GCT, local official Z-read, or local official e-journal finalization.

## Duplicate Hash Decision

- `payload_hash` has a **non-unique index** on `(tenant_id, sales_machine_profile_id, payload_hash)`. Duplicate rows are intentionally preserved with `status = duplicate` for full audit visibility.
- Hard unique constraint applies only to `(tenant_id, sales_machine_profile_id, batch_reference)` to prevent replay attacks.

---

## 2. Story Scope Boundaries

### In Scope:

1. **Offline Sales Import Ledger** (`offline_sales_imports` table):
   - `id` (UUID PK)
   - `tenant_id`, `branch_id`, `sales_machine_profile_id` (FK + tenant scope)
   - `batch_id` (FK → `offline_sync_batches.id`)
   - `offline_sequence_number` (string) — the terminal-assigned provisional number (e.g., `INV-T01-0042`)
   - `payload_hash` (string) — SHA-256 of the raw submitted payload for deduplication
   - `raw_payload` (JSON) — the full submitted cart/transaction payload as received
   - `status` (string, default: `pending`) — lifecycle states: `pending`, `validated`, `posted`, `rejected`, `duplicate`
   - `rejection_reason` (string, nullable) — why it was rejected, if applicable
   - `reconciled_sale_id` (UUID, nullable, FK → `sales.id`) — the official Sale created after server-side posting
   - `submitted_at` (timestamp)
   - `reconciled_at` (timestamp, nullable)
   - `created_at`, `updated_at`

2. **Offline Sync Batches** (`offline_sync_batches` table):
   - `id` (UUID PK)
   - `tenant_id`, `branch_id`, `sales_machine_profile_id`
   - `batch_reference` (string) — client-generated unique batch ID sent during sync
   - `status` (string, default: `received`) — states: `received`, `processing`, `completed`, `failed`
   - `submitted_import_count` (integer, default: 0) — how many imports were submitted in this batch
   - `processed_count` (integer, default: 0) — how many were processed
   - `failed_count` (integer, default: 0) — how many were rejected
   - `sync_started_at` (timestamp, nullable)
   - `sync_completed_at` (timestamp, nullable)
   - `created_at`, `updated_at`

3. **Offline Terminal Journals** (`offline_terminal_journals` table):
   - `id` (UUID PK)
   - `tenant_id`, `branch_id`, `sales_machine_profile_id`
   - `journal_date` (date) — the business date this journal covers
   - `status` (string, default: `provisional`) — states: `provisional`, `reconciled`, `voided`
   - `provisional_gross_total` (decimal 15,4, default: 0) — sum of all submitted imports for this date/terminal
   - `provisional_item_count` (integer, default: 0)
   - `reconciliation_notes` (text, nullable)
   - `reconciled_at` (timestamp, nullable)
   - `created_at`, `updated_at`

4. **Offline Sequence Recoveries** (`offline_sequence_recoveries` table):
   - `id` (UUID PK)
   - `tenant_id`, `sales_machine_profile_id`
   - `recovery_type` (string) — why recovery was needed: `gap_detected`, `duplicate_detected`, `range_depleted`, `manual_correction`
   - `affected_prefix` (string) — which prefix is affected
   - `affected_range_start`, `affected_range_end` (integer, nullable) — the gap or duplicate range
   - `resolution` (string, nullable) — action taken: `range_extended`, `prefix_reassigned`, `imports_rejected`
   - `resolved_by_user_id` (UUID, nullable, FK → `users.id`)
   - `resolved_at` (timestamp, nullable)
   - `notes` (text, nullable)
   - `created_at`, `updated_at`

5. **OfflineReconciliationService Skeleton** (`app/Services/POS/OfflineSync/OfflineReconciliationService.php`):
   - Stub class with documented method signatures only (no implementation logic yet):
     - `receiveImportBatch(SalesMachineProfile $profile, array $batchPayload): OfflineSyncBatch`
     - `validateImport(OfflineSalesImport $import): bool`
     - `deduplicateImport(OfflineSalesImport $import): bool`
     - `reconcileImport(OfflineSalesImport $import): ?Sale`
     - `finalizeJournal(OfflineTerminalJournal $journal): void`
   - Each method must throw `\BadMethodCallException('Not yet implemented.')` if called.
   - This ensures clean dependency injection and prevents silent no-ops.

6. **Sync Endpoint Stub** (`POST /api/pos/offline-sync`):
   - A route and controller stub under `permission:create_sale` + `branch` middleware.
   - Returns HTTP `503 Service Unavailable` with a machine-readable response:
     ```json
     {
       "error": "OFFLINE_SYNC_NOT_AVAILABLE",
       "message": "Offline sync ingestion is not yet available.",
       "retry_after": null
     }
     ```
   - This prevents silent failures if a POS client mistakenly calls the endpoint early.

### Out of Scope:

- Any logic to process, validate, or post an offline import (those belong to Story 28.7+).
- Client-side cart store, offline queue, or IndexedDB structures.
- Provisional receipt generation or local sequence counter management.
- Official GCT update, official Z-read generation, or official e-journal creation from offline imports.
- Any BIR-labeled, BIR-certified, or compliance-claimed labeling of offline features.

---

## 3. Core Business & Safety Rules

### A. Deduplication is mandatory before any provisional-to-official posting
- `payload_hash` must be checked for uniqueness within `(tenant_id, sales_machine_profile_id)` before accepting a new import.
- If a duplicate hash is detected, the import record must be saved with `status = 'duplicate'` and the terminal must be notified.

### B. Provisional data must never mutate official financial records
- `offline_terminal_journals.provisional_gross_total` and `provisional_item_count` are read-only aggregates. They summarize submitted imports. They do **not** update `grand_cumulative_total` on `sales_machine_profiles` until the import is fully reconciled and posted.
- `reconciled_sale_id` must remain `null` until a proper server-side sale is created.

### C. Batch reference uniqueness
- `(tenant_id, sales_machine_profile_id, batch_reference)` must be unique to prevent replay attacks or accidental double-submissions.

### D. Sequence recovery is an admin-only action
- Only users with `manage_offline_sales_settings` may create or resolve `offline_sequence_recoveries`.

---

## 4. Bounded Monorepo Directory Setup

```
database/migrations/
└── [timestamp]_create_offline_sync_tables.php

app/Models/
├── OfflineSalesImport.php
├── OfflineSyncBatch.php
├── OfflineTerminalJournal.php
└── OfflineSequenceRecovery.php

app/Services/POS/OfflineSync/
└── OfflineReconciliationService.php   # Skeleton only

app/Http/Controllers/POS/
└── OfflineSyncController.php          # Stub returning 503

routes/web.php or routes/api.php
└── POST /api/pos/offline-sync         # Stub route
```

---

## 5. RBAC & Security Boundaries

- The sync endpoint stub requires `permission:create_sale` (POS operator) and `branch` middleware — consistent with live checkout routes.
- Sequence recovery records require `manage_offline_sales_settings` for creation and resolution.
- No cashier role may read or write `offline_sequence_recoveries`.
- All models must enforce `tenant_id` scoping.

---

## 6. Test Matrix

| Test ID | Level | Target Domain | Scenario Description | Expected Outcome |
| :--- | :--- | :--- | :--- | :--- |
| **TC-28.6-01** | Unit | Migration | Run database migrations | All 4 tables created; columns and defaults verified; unique constraints applied. |
| **TC-28.6-02** | Unit | Model | Create `OfflineSyncBatch` with valid fields | Record persists; tenant/branch/profile scope enforced. |
| **TC-28.6-03** | Unit | Model | Create `OfflineSalesImport` with pending status | `status = pending`; `reconciled_sale_id = null`; `reconciled_at = null`. |
| **TC-28.6-04** | Integration | Deduplication | Insert `OfflineSalesImport` with duplicate `payload_hash` | Second import saved with `status = duplicate`; first remains `pending`. |
| **TC-28.6-05** | Integration | Batch | Duplicate `batch_reference` within same terminal | DB unique constraint blocks second batch; error is thrown. |
| **TC-28.6-06** | Unit | Service | Call any method on `OfflineReconciliationService` | `BadMethodCallException` with `'Not yet implemented.'` message thrown. |
| **TC-28.6-07** | Integration | Endpoint | POST `/api/pos/offline-sync` authenticated as cashier | HTTP 503 with `OFFLINE_SYNC_NOT_AVAILABLE` JSON error code. |
| **TC-28.6-08** | Integration | Endpoint | POST `/api/pos/offline-sync` unauthenticated | HTTP 401 JSON response (not redirect). |
| **TC-28.6-09** | Integration | RBAC | Cashier attempts to create `OfflineSequenceRecovery` | HTTP 403; no record created. |
| **TC-28.6-10** | Unit | Model | `provisional_gross_total` is correctly typed | Cast to `decimal:4`; asserting a numeric value survives round-trip. |
